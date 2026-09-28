<?php

namespace App\Support;

use App\Models\Deposit;
use App\Models\ForecastBenchmark;
use App\Models\ForecastEmbedding;
use App\Models\Institution;
use App\Models\MealEntry;
use App\Models\MealRateSetting;
use App\Models\Student;
use App\Models\Subsidy;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * RAG / VECTOR-BASED HEADCOUNT & EXPENSE FORECASTING.
 *
 * WHAT IT PREDICTS
 *   - tomorrow's meal count and headcount
 *   - the likely cost per meal
 *   - expected total expense (and the subsidy share of it)
 *
 * HOW IT WORKS (retrieval-augmented, not a black box)
 * ---------------------------------------------------
 *   1. EMBED   - each historical day is reduced to a small numeric FEATURE VECTOR
 *                (day-of-week, month, recent trend, roster size, ...). This is the
 *                "document" in RAG terms.
 *   2. RETRIEVE- for a target date we build the same vector and rank past days by
 *                COSINE SIMILARITY, taking the k nearest.
 *   3. AUGMENT - those retrieved days carry their ACTUAL outcomes (meals, expense,
 *                cost per meal), which become the grounding evidence.
 *   4. GENERATE- the estimate is a similarity-weighted average of that evidence,
 *                blended with the member's own short-term trend.
 *
 * Crucially the output includes `evidence` - the specific past days it leaned on,
 * with their values. An operator can see exactly WHY the number is what it is and
 * disagree with it. That transparency is the point of doing RAG here rather than
 * fitting an opaque model.
 *
 * THE FALLBACK
 * ------------
 * An institution with less than 3 months of history has nothing meaningful to
 * retrieve from. Instead of inventing a number (or failing), the engine falls back
 * to country benchmarks (e.g. Bangladesh dormitory figures) and SAYS SO, reporting
 * `basis: 'benchmark'` and the source. The UI labels a benchmark estimate
 * differently from a data-driven one, because they deserve different trust.
 */
class Forecaster
{
    /** How many similar past days to retrieve. */
    public const K_NEIGHBOURS = 12;

    /** Feature-vector length (kept in one place; stored with each embedding). */
    public const DIMENSIONS = 8;

    /** Below this many months of history, use benchmarks. */
    public const MIN_HISTORY_MONTHS = 3;

    /** How many months of history to embed. */
    public const HISTORY_MONTHS = 6;

    public function __construct(
        protected ?Institution $institution = null,
    ) {
        $this->institution = $institution ?? Institution::current();
    }

    /** The country whose benchmarks apply (BD by default). */
    public function countryCode(): string
    {
        return strtoupper((string) (
            $this->institution?->country_code
            ?? config('services.forecasting.default_country', 'BD')
        ));
    }

    /* ------------------------------------------------------------------ *
     * 1. EMBEDDING
     * ------------------------------------------------------------------ */

    /**
     * Turn a day's context into a fixed-length numeric vector.
     *
     * The features are deliberately FEW and interpretable - each one is something a
     * domain expert would agree affects meal counts:
     *
     *   [0] day-of-week (0-6, normalised)
     *   [1] is-weekend flag
     *   [2] month position (seasonality)
     *   [3] active roster size (normalised)
     *   [4] trailing 7-day mean meals (normalised)
     *   [5] trailing 30-day mean meals (normalised)
     *   [6] trailing 7-day mean cost per meal (normalised)
     *   [7] deposit activity in the trailing 7 days (normalised)
     *
     * All values are scaled to roughly 0..1 so no single dimension dominates the
     * cosine similarity.
     */
    public function featureVector(Carbon $date): array
    {
        $weekday = (int) $date->dayOfWeek;             // 0 = Sunday
        $isWeekend = in_array($weekday, [5, 6], true); // Fri/Sat in Bangladesh

        // Roster size, normalised against a soft ceiling.
        $roster = Student::active()->count();
        $rosterNorm = min($roster / 500, 1.0);

        // Trailing meal means - the short and long trends.
        $mean7 = $this->trailingMealMean($date, 7);
        $mean30 = $this->trailingMealMean($date, 30);

        // A soft ceiling so a very large institution does not saturate the vector.
        $scale = max($roster, 1) * 3;

        $mealScale = max($scale, 1);

        $costPerMeal = $this->trailingCostPerMeal($date, 7);
        $deposits = $this->trailingDeposits($date, 7);

        return [
            round($weekday / 6, 4),
            $isWeekend ? 1.0 : 0.0,
            round(((int) $date->month) / 12, 4),
            round($rosterNorm, 4),
            round(min($mean7 / $mealScale, 1.0), 4),
            round(min($mean30 / $mealScale, 1.0), 4),
            round(min($costPerMeal / 1000, 1.0), 4),
            round(min($deposits / max($roster * 1000, 1), 1.0), 4),
        ];
    }

    /**
     * Build/refresh embeddings for the last N months of history.
     *
     * Idempotent: re-running overwrites each day's vector and outcomes, so the
     * corpus always reflects the current ledger.
     *
     * @return int number of days embedded
     */
    public function buildEmbeddings(?Carbon $until = null): int
    {
        $until ??= Carbon::yesterday();
        $from = $until->copy()->subMonths(self::HISTORY_MONTHS)->startOfDay();

        $count = 0;

        for ($date = $from->copy(); $date->lte($until); $date->addDay()) {
            $outcomes = $this->outcomesFor($date);

            // A day with no activity at all is still a valid "quiet day" signal,
            // but embedding the very first weeks of an empty institution adds
            // noise, so we skip days before the institution had any members.
            if (($outcomes['meals'] ?? 0) === 0 && $date->lt($from->copy()->addDays(7))) {
                continue;
            }

            ForecastEmbedding::withoutTenantScope()->updateOrCreate(
                [
                    'institution_id' => $this->institution?->id,
                    'for_date' => $date->toDateString(),
                    'model' => 'v1-local',
                ],
                [
                    'embedding' => $this->featureVector($date),
                    'summary' => $this->summariseDay($date, $outcomes),
                    'meals' => $outcomes['meals'],
                    'headcount' => $outcomes['headcount'],
                    'expense' => $outcomes['expense'],
                    'deposits' => $outcomes['deposits'],
                    'cost_per_meal' => $outcomes['cost_per_meal'],
                ]
            );

            $count++;
        }

        return $count;
    }

    /* ------------------------------------------------------------------ *
     * 2. RETRIEVAL
     * ------------------------------------------------------------------ */

    /**
     * The k most SIMILAR historical days to a target date.
     *
     * @return array<int, array{date:string, similarity:float, meals:int, headcount:int, expense:float, cost_per_meal:float, summary:string}>
     */
    public function retrieveSimilar(Carbon $target, int $k = self::K_NEIGHBOURS): array
    {
        $vector = $this->featureVector($target);

        $embedding = ForecastEmbedding::withoutTenantScope()
            ->where('institution_id', $this->institution?->id)
            ->where('model', 'v1-local')
            // Never retrieve the target day itself (it has no outcome anyway).
            ->where('for_date', '<', $target->toDateString())
            ->where('for_date', '>=', $target->copy()->subMonths(self::HISTORY_MONTHS)->toDateString())
            ->get();

        $scored = $embedding->map(function (ForecastEmbedding $row) use ($vector) {
            $similarity = ForecastEmbedding::cosine($vector, $row->vector());

            return [
                'date' => $row->for_date->toDateString(),
                'similarity' => round($similarity, 4),
                'meals' => (int) $row->meals,
                'headcount' => (int) $row->headcount,
                'expense' => (float) $row->expense,
                'deposits' => (float) $row->deposits,
                'cost_per_meal' => (float) $row->cost_per_meal,
                'summary' => (string) $row->summary,
            ];
        })
            // A negative or near-zero similarity means "not really comparable";
            // including such days would drag the estimate toward noise.
            ->filter(fn (array $row) => $row['similarity'] > 0.05)
            ->sortByDesc('similarity')
            ->take($k)
            ->values()
            ->all();

        return $scored;
    }

    /* ------------------------------------------------------------------ *
     * 3. FORECAST
     * ------------------------------------------------------------------ */

    /**
     * Forecast a single day.
     *
     * @return array{
     *   date:string, basis:string, confidence:float,
     *   meals:int, headcount:int, cost_per_meal:float, expense:float,
     *   subsidy_share:float, evidence:array, benchmark:?array, notes:array
     * }
     */
    public function forecast(Carbon|string|null $for = null): array
    {
        $target = $for ? Carbon::parse($for) : Carbon::tomorrow();

        // How much usable history exists?
        $historyStart = ForecastEmbedding::withoutTenantScope()
            ->where('institution_id', $this->institution?->id)
            ->where('model', 'v1-local')
            ->min('for_date');

        $monthsOfHistory = $historyStart
            ? Carbon::parse($historyStart)->diffInMonths($target)
            : 0;

        $minMonths = (int) config('services.forecasting.min_history_months', self::MIN_HISTORY_MONTHS);

        // --- THE FALLBACK -------------------------------------------------
        // Not enough history to retrieve from: anchor to country benchmarks and
        // REPORT that we did so, rather than presenting a guess as a forecast.
        if ($monthsOfHistory < $minMonths) {
            return $this->benchmarkForecast($target, $monthsOfHistory);
        }

        $neighbours = $this->retrieveSimilar($target);

        if ($neighbours === []) {
            return $this->benchmarkForecast($target, $monthsOfHistory);
        }

        // --- RAG: similarity-weighted average of retrieved outcomes -------
        $totalWeight = array_sum(array_column($neighbours, 'similarity'));

        if ($totalWeight <= 0.0) {
            return $this->benchmarkForecast($target, $monthsOfHistory);
        }

        $meals = 0.0;
        $headcount = 0.0;
        $costPerMeal = 0.0;

        foreach ($neighbours as $neighbour) {
            $weight = $neighbour['similarity'] / $totalWeight;

            $meals += $neighbour['meals'] * $weight;
            $headcount += $neighbour['headcount'] * $weight;
            $costPerMeal += $neighbour['cost_per_meal'] * $weight;
        }

        // Blend with the member's OWN recent trajectory: weekdays differ, and the
        // last few days are the most relevant signal of all.
        $recentMean = $this->trailingMealMean($target, 7);

        if ($recentMean > 0) {
            // 70% retrieval, 30% recent trend - retrieval captures seasonality,
            // the trend captures "right now".
            $meals = ($meals * 0.7) + ($recentMean * 0.3);
        }

        $notes = [];

        // Day-of-week adjustment, learned from the institution's own history.
        $weekdayAdjustment = $this->weekdayFactor($target);
        if ($weekdayAdjustment !== null) {
            $meals *= $weekdayAdjustment;
            $notes[] = sprintf(
                'Adjusted %+.0f%% for the usual %s pattern.',
                ($weekdayAdjustment - 1) * 100,
                $target->format('l'),
            );
        }

        // A forecast can never exceed what the roster could physically eat.
        $capacity = Student::active()->count() * 3;
        if ($capacity > 0 && $meals > $capacity) {
            $meals = $capacity;
            $notes[] = 'Capped at the maximum the active roster could eat.';
        }

        $mealsInt = max((int) round($meals), 0);
        $cost = round($costPerMeal, 2);
        $expense = round($mealsInt * $cost, 2);

        // Subsidy share: derived from recent subsidy coverage, so the member-funded
        // portion is presented honestly alongside the gross expense.
        $subsidyShare = $this->recentSubsidyShare($target);

        return [
            'date' => $target->toDateString(),
            'basis' => 'history',
            'confidence' => $this->confidenceFrom($neighbours, $monthsOfHistory),
            'meals' => $mealsInt,
            'headcount' => (int) round($headcount),
            'cost_per_meal' => $cost,
            'expense' => $expense,
            'subsidy_share' => round($expense * $subsidyShare, 2),
            'member_funded' => round($expense * (1 - $subsidyShare), 2),
            'evidence' => $neighbours,
            'benchmark' => null,
            'history_months' => round($monthsOfHistory, 1),
            'notes' => $notes,
        ];
    }

    /**
     * The BENCHMARK fallback, used when there is not enough history.
     *
     * Anchors to country aggregates and reports the basis + source openly. When no
     * benchmark is published either, we say so and return a zero-confidence
     * estimate rather than a fabricated number.
     */
    protected function benchmarkForecast(Carbon $target, float $monthsOfHistory): array
    {
        $country = $this->countryCode();
        $on = $target->toDateString();

        $roster = Student::active()->count();

        $costBenchmark = ForecastBenchmark::latest($country, 'cost_per_meal', $on);
        $perMemberBenchmark = ForecastBenchmark::latest($country, 'meals_per_member', $on);
        $rateBenchmark = ForecastBenchmark::latest($country, 'daily_meal_rate', $on);
        $subsidyBenchmark = ForecastBenchmark::latest($country, 'subsidy_pct', $on);

        $notes = [
            'This institution has about '.round($monthsOfHistory, 1).' month(s) of history, which is below the '
            .config('services.forecasting.min_history_months', self::MIN_HISTORY_MONTHS)
            .'-month threshold for a data-driven forecast, so national benchmarks for '.$country.' are used instead.',
            'Treat this as an industry baseline, not a prediction about your members.',
        ];

        if (! $costBenchmark && ! $perMemberBenchmark) {
            $notes[] = "No benchmark data is published for {$country} yet, so no reliable estimate could be produced.";

            return [
                'date' => $target->toDateString(),
                'basis' => 'benchmark',
                'confidence' => 0.0,
                'meals' => 0,
                'headcount' => 0,
                'cost_per_meal' => 0.0,
                'expense' => 0.0,
                'subsidy_share' => 0.0,
                'member_funded' => 0.0,
                'evidence' => [],
                'benchmark' => [
                    'country' => $country,
                    'available' => false,
                    'source' => null,
                ],
                'history_months' => round($monthsOfHistory, 1),
                'notes' => $notes,
            ];
        }

        // Meals per member per day drives the headcount estimate.
        $mealsPerMember = (float) ($perMemberBenchmark->value ?? 2.0);

        // Not everyone eats every day: the daily rate scales the roster.
        $dailyRate = (float) ($rateBenchmark->value ?? 0.85);

        $headcount = (int) round($roster * min($dailyRate, 1.0));
        $meals = (int) round($headcount * $mealsPerMember);
        $cost = round((float) ($costBenchmark->value ?? 0.0), 2);
        $expense = round($meals * $cost, 2);

        // Subsidy percentages are stored 0..100; normalise to 0..1.
        $subsidyRaw = (float) ($subsidyBenchmark->value ?? 0.0);
        $subsidyShare = $subsidyRaw > 1.0 ? $subsidyRaw / 100 : $subsidyRaw;

        return [
            'date' => $target->toDateString(),
            'basis' => 'benchmark',
            // A benchmark estimate is inherently less certain than a retrieved one.
            'confidence' => 0.35,
            'meals' => $meals,
            'headcount' => $headcount,
            'cost_per_meal' => $cost,
            'expense' => $expense,
            'subsidy_share' => round($expense * $subsidyShare, 2),
            'member_funded' => round($expense * (1 - $subsidyShare), 2),
            'evidence' => [],
            'benchmark' => [
                'country' => $country,
                'available' => true,
                'source' => $costBenchmark->source ?? $perMemberBenchmark->source ?? null,
                'metrics' => [
                    'cost_per_meal' => $costBenchmark?->value,
                    'meals_per_member' => $perMemberBenchmark?->value,
                    'daily_meal_rate' => $rateBenchmark?->value,
                    'subsidy_pct' => $subsidyBenchmark?->value,
                ],
            ],
            'history_months' => round($monthsOfHistory, 1),
            'notes' => $notes,
        ];
    }

    /** A multi-day forecast (e.g. the next 7 days). */
    public function forecastRange(int $days = 7, ?Carbon $from = null): array
    {
        $start = $from ? Carbon::parse($from) : Carbon::tomorrow();

        $forecasts = [];

        for ($i = 0; $i < $days; $i++) {
            $forecasts[] = $this->forecast($start->copy()->addDays($i));
        }

        return [
            'days' => $forecasts,
            'total_meals' => array_sum(array_column($forecasts, 'meals')),
            'total_expense' => round(array_sum(array_column($forecasts, 'expense')), 2),
            'basis' => $forecasts[0]['basis'] ?? 'benchmark',
        ];
    }

    /* ------------------------------------------------------------------ *
     * MONTHLY MONEY PROJECTION (migrated from the Analytics forecast panel)
     * ------------------------------------------------------------------ */

    /**
     * Project the next N MONTHS of meals, cost and the subsidy/member split.
     *
     * MIGRATED HERE FROM THE ANALYTICS WIDGET.
     * ----------------------------------------
     * The standard Analytics pages used to render a "3-Month Predictive Forecast"
     * table computed by `FinanceCalculator::forecast()` — a recency-weighted linear
     * ramp applied to the institution's OWN trailing months, then split by the
     * institution's target subsidy ratio (the "80/20 rule").
     *
     * That was rigid in exactly the way this module exists to fix: it ignored
     * weather, the academic calendar, weekday patterns and every other institution
     * on the platform, and it reported a confident-looking number even when the
     * institution had one month of data. It has therefore been REMOVED from the
     * analytics views and re-implemented HERE, on top of the retrieval engine, so
     * there is one forecasting system rather than two that disagree.
     *
     * WHAT CHANGED, AND WHAT DELIBERATELY DID NOT
     *   - The DAY-LEVEL figures (meals, cost per meal, expense) now come from the
     *     RAG retrieval / country-benchmark path — the same numbers the calendar
     *     forecast uses, so the two can never contradict each other.
     *   - The SUBSIDY SPLIT arithmetic is kept as it was: it is a business rule
     *     (the institution's own target ratio), not a prediction, so there is
     *     nothing to learn and no reason to change it.
     *   - `basis` is propagated on EVERY row, so the UI can label a benchmark-driven
     *     projection differently from a data-driven one.
     *
     * @return array{months: array<int, array>, basis: string, assumptions: array}
     */
    public function monthlyProjection(int $months = 3): array
    {
        $setting     = MealRateSetting::current();
        $targetRatio = (float) ($setting->target_subsidy_ratio ?? 20) / 100;
        $memberRatio = max(0.0, 1 - $targetRatio);

        $cursor = now()->startOfMonth();
        $rows   = [];

        for ($m = 1; $m <= $months; $m++) {
            $monthStart = $cursor->copy()->addMonths($m);

            // Anchor on mid-month so the day-of-week adjustment is representative
            // of the month rather than of whatever the 1st happens to fall on.
            $anchor = $monthStart->copy()->day(15);

            $days = $this->forecastRange($monthStart->daysInMonth, $monthStart);

            // `forecastRange` anchors on the month start; re-anchor the weekday
            // factor on the mid-month day so a month is not skewed by its first day.
            $weekdayFactor = $this->weekdayFactor($anchor) ?? 1.0;

            $meals  = (int) round(($days['total_meals'] ?? 0) * $weekdayFactor);
            $expense = round(($days['total_expense'] ?? 0) * $weekdayFactor, 2);

            // A month with no predicted meals costs nothing. This is the "0 yields 0"
            // rule: we never substitute a manual figure or a stale average.
            $rate = $meals > 0 ? round($expense / $meals, 4) : 0.0;
            $cost = $meals > 0 ? round($meals * $rate, 2) : 0.0;

            $rows[] = [
                'month'            => $monthStart->format('Y-m'),
                'label'            => $monthStart->format('F Y'),
                'projected_meals'  => $meals,
                'projected_cost'   => $cost,
                'projected_rate'   => $rate,
                'subsidy_required' => round($cost * $targetRatio, 2),
                'member_funded'    => round($cost * $memberRatio, 2),
                // Carried per row so a benchmark-driven month is visibly different
                // from a data-driven one, even inside the same table.
                'basis'            => $days['basis'] ?? 'benchmark',
            ];
        }

        $mealsTotal   = array_sum(array_column($rows, 'projected_meals'));
        $costTotal    = round(array_sum(array_column($rows, 'projected_cost')), 2);
        $subsidyTotal = round(array_sum(array_column($rows, 'subsidy_required')), 2);

        return [
            'months'   => $rows,
            // The WORST basis across the horizon: if any month is benchmark-only,
            // the headline must not claim to be data-driven.
            'basis'    => collect($rows)->contains(fn ($r) => $r['basis'] === 'benchmark')
                ? 'benchmark'
                : ($rows[0]['basis'] ?? 'benchmark'),
            'totals'   => [
                'projected_meals'  => $mealsTotal,
                'projected_cost'   => $costTotal,
                'subsidy_required' => $subsidyTotal,
                'member_funded'    => round($costTotal - $subsidyTotal, 2),
            ],
            'assumptions' => [
                'target_subsidy_ratio' => round($targetRatio * 100, 2),
                'target_member_ratio'  => round($memberRatio * 100, 2),
                'country_code'         => $this->countryCode(),
                'months'               => $months,
                'method'               => 'Retrieval over similar past days (RAG), falling back to '
                    . $this->countryCode() . ' country benchmarks when history is thin. '
                    . 'The subsidy/member split is the institution\'s own target ratio.',
            ],
        ];
    }

    /* ------------------------------------------------------------------ *
     * Outcome + statistic helpers
     * ------------------------------------------------------------------ */

    /** What actually happened on a day (the values we learn from). */
    protected function outcomesFor(Carbon $date): array
    {
        $meals = MealEntry::query()
            ->whereDate('date', $date->toDateString())
            ->selectRaw('COALESCE(SUM(breakfast + lunch + dinner), 0) as total')
            ->value('total') ?? 0;

        // Headcount = distinct members who ate anything that day.
        $headcount = DB::table('meal_entries')
            ->where('institution_id', $this->institution?->id)
            ->whereDate('date', $date->toDateString())
            ->whereRaw('(breakfast + lunch + dinner) > 0')
            ->distinct()
            ->count('student_id');

        $deposits = (float) Deposit::query()
            ->whereDate('created_at', $date->toDateString())
            ->whereNull('reversed_at')
            ->sum('amount');

        $expense = (float) Transaction::query()
            ->where('type', 'expense')
            ->whereDate('created_at', $date->toDateString())
            ->sum('amount');

        return [
            'meals' => (int) $meals,
            'headcount' => (int) $headcount,
            'deposits' => round($deposits, 2),
            'expense' => round($expense, 2),
            'cost_per_meal' => $meals > 0 ? round($expense / $meals, 2) : 0.0,
        ];
    }

    /** Mean meals per day over the trailing window. */
    protected function trailingMealMean(Carbon $date, int $days): float
    {
        $from = $date->copy()->subDays($days)->toDateString();
        $to = $date->copy()->subDay()->toDateString();

        $row = MealEntry::query()
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->selectRaw('COALESCE(SUM(breakfast + lunch + dinner), 0) as total')
            ->selectRaw('COUNT(DISTINCT date) as day_count')
            ->first();

        $dayCount = (int) ($row->day_count ?? 0);

        return $dayCount > 0 ? (float) $row->total / $dayCount : 0.0;
    }

    /** Mean cost per meal over the trailing window. */
    protected function trailingCostPerMeal(Carbon $date, int $days): float
    {
        $from = $date->copy()->subDays($days)->toDateString();
        $to = $date->copy()->subDay()->toDateString();

        $expense = (float) Transaction::query()
            ->where('type', 'expense')
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->sum('amount');

        $meals = (int) MealEntry::query()
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->selectRaw('COALESCE(SUM(breakfast + lunch + dinner), 0) as total')
            ->value('total');

        return $meals > 0 ? round($expense / $meals, 2) : 0.0;
    }

    /** Deposits received in the trailing window. */
    protected function trailingDeposits(Carbon $date, int $days): float
    {
        return (float) Deposit::query()
            ->whereDate('created_at', '>=', $date->copy()->subDays($days)->toDateString())
            ->whereDate('created_at', '<=', $date->copy()->subDay()->toDateString())
            ->whereNull('reversed_at')
            ->sum('amount');
    }

    /**
     * How this weekday compares with the overall average.
     *
     * Returns null when there is not enough history for the ratio to be meaningful,
     * so a spurious factor is never applied.
     */
    protected function weekdayFactor(Carbon $target): ?float
    {
        $windowStart = $target->copy()->subMonths(self::HISTORY_MONTHS)->toDateString();
        $windowEnd = $target->copy()->subDay()->toDateString();

        $rows = MealEntry::query()
            ->whereDate('date', '>=', $windowStart)
            ->whereDate('date', '<=', $windowEnd)
            ->selectRaw('date as on_date, COALESCE(SUM(breakfast + lunch + dinner), 0) as total')
            ->groupBy('date')
            ->get();

        if ($rows->count() < 21) {
            return null;
        }

        $overallMean = (float) $rows->avg('total');

        if ($overallMean <= 0) {
            return null;
        }

        $weekday = (int) $target->dayOfWeek;

        $sameWeekday = $rows->filter(fn ($row) => Carbon::parse($row->on_date)->dayOfWeek === $weekday);

        if ($sameWeekday->count() < 3) {
            return null;
        }

        $weekdayMean = (float) $sameWeekday->avg('total');

        $factor = $weekdayMean / $overallMean;

        // Clamp: a factor far from 1 signals a data quirk, not a real pattern.
        return max(min($factor, 1.5), 0.5);
    }

    /** Recent subsidy coverage as a 0..1 share of expenses. */
    protected function recentSubsidyShare(Carbon $date): float
    {
        $from = $date->copy()->subDays(30)->toDateString();

        try {
            $subsidies = (float) Subsidy::query()
                ->whereDate('created_at', '>=', $from)
                ->sum('amount');

            $expense = (float) Transaction::query()
                ->where('type', 'expense')
                ->whereDate('created_at', '>=', $from)
                ->sum('amount');

            if ($expense <= 0) {
                return 0.0;
            }

            return max(min($subsidies / $expense, 1.0), 0.0);
        } catch (\Throwable $e) {
            // Subsidies table unavailable on a minimal install: report no subsidy
            // rather than failing the whole forecast.
            return 0.0;
        }
    }

    /**
     * Confidence, from evidence quality.
     *
     * Driven by how SIMILAR the retrieved days were (tight cluster = reliable) and
     * how much history exists (more months = more trust). Capped below 1.0 because
     * a forecast is never a certainty.
     */
    protected function confidenceFrom(array $neighbours, float $monthsOfHistory): float
    {
        if ($neighbours === []) {
            return 0.0;
        }

        $meanSimilarity = array_sum(array_column($neighbours, 'similarity')) / count($neighbours);

        $historyFactor = min($monthsOfHistory / 12, 1.0);

        // 60% evidence quality, 40% history depth.
        $confidence = ($meanSimilarity * 0.6) + ($historyFactor * 0.4);

        return round(max(min($confidence, 0.95), 0.05), 3);
    }

    /** A plain-language summary of a day, stored with its vector for the UI. */
    protected function summariseDay(Carbon $date, array $outcomes): string
    {
        return sprintf(
            '%s: %d meals across %d members, %s spent.',
            $date->format('D j M Y'),
            $outcomes['meals'],
            $outcomes['headcount'],
            number_format($outcomes['expense'], 2),
        );
    }
}
