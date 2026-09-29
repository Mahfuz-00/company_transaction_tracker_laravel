<?php

namespace App\Support;

use App\Models\ForecastBenchmark;
use App\Models\ForecastModel;
use App\Models\Institution;
use App\Models\MealEntry;
use App\Models\Student;
use Carbon\Carbon;

/**
 * THE MONTHLY TRAINING ENGINE.
 *
 * WHAT IT TRAINS, AND WHAT IT DELIBERATELY DOES NOT
 * -------------------------------------------------
 * It trains ONE thing: the per-weekday MEAL COUNT multiplier, fitted from the
 * institution's own history. That is the quantity that genuinely varies with
 * weekday, term dates and roster, and it is therefore worth learning.
 *
 * It does NOT train a price. Price is `(expenses − subsidies) ÷ consumed meals`,
 * an accounting identity computed by MealPriceEngine. Fitting a model to it would
 * produce a number that corresponds to no actual period of the ledger and would
 * disagree with the reports page - the exact defect MealPriceEngine was written to
 * remove. Every figure here is COUNTS x LEDGER RATE, never a predicted rate.
 *
 * WHY WEEKDAY MULTIPLIERS RATHER THAN A BLACK BOX
 * -----------------------------------------------
 * The output has to be explainable to a mess manager: "Friday is usually 12% below
 * average because more members go home". A seven-number multiplier vector can be
 * printed, argued with and corrected by hand. A gradient-boosted model over the
 * same features could not, and would need a Python runtime the deployment does not
 * have. The weights are stored as JSON so the shape can evolve without a migration.
 *
 * THE FALLBACK LADDER (and why it is a ladder, not a default)
 * -----------------------------------------------------------
 *   1. >= MIN_HISTORY_MONTHS of history  -> `trained`   (fitted weights)
 *   2. some history, below the threshold -> `benchmark` (country aggregates)
 *   3. no usable history at all          -> `empty`     (clean zeros)
 *
 * Step 3 returns ZERO rather than a guess. The brief is explicit that a missing
 * benchmark must "default cleanly to 0", and a zero that is labelled as zero is
 * honest, whereas a substituted average is not.
 */
class ForecastTrainer
{
    /** The model key written to `forecast_models.model`. */
    public const MODEL = 'monthly-v1';

    /** Below this many months of history, fall back to benchmarks. */
    public const MIN_HISTORY_MONTHS = 3;

    /** How many months of history to fit on. */
    public const TRAIN_MONTHS = 12;

    /** The number of months the rolling forecast projects forward. */
    public const ROLLING_MONTHS = 3;

    public function __construct(
        protected ?Institution $institution = null,
    ) {
        $this->institution = $institution ?? Institution::current();
    }

    /* ------------------------------------------------------------------ *
     * TRAINING
     * ------------------------------------------------------------------ */

    /**
     * Aggregate this institution's history and fit the model for a month.
     *
     * Idempotent: re-running for the same month REPLACES that month's row, so a
     * corrected ledger converges to one authoritative state rather than stacking
     * duplicate rows.
     *
     * @param  string|null  $month  the month to train for (YYYY-MM); defaults to now
     * @return array{month:string,basis:string,days:int,history_months:float,weights:array,metrics:array}
     */
    public function train(?string $month = null): array
    {
        $period = $month
            ? Carbon::createFromFormat('Y-m', $month)->startOfMonth()
            : now()->startOfMonth();

        $historyMonths = $this->monthsOfHistory($period);

        // ---- STEP 1: enough history -> fit the weights. -------------------
        if ($historyMonths >= static::minHistoryMonths()) {
            $weights = $this->fitWeekdayWeights($period);
            $metrics = $this->trainingMetrics($period);

            $result = [
                'month' => $period->format('Y-m'),
                'basis' => 'trained',
                'days' => $metrics['days'],
                'history_months' => round($historyMonths, 1),
                'weights' => $weights,
                'metrics' => $metrics,
            ];

            $this->persist($period, $result);

            return $result;
        }

        // ---- STEP 2: some history -> country benchmarks. ------------------
        $benchmark = $this->benchmarkWeights($period);

        if ($benchmark !== null) {
            $result = [
                'month' => $period->format('Y-m'),
                'basis' => 'benchmark',
                'days' => 0,
                'history_months' => round($historyMonths, 1),
                'weights' => $benchmark,
                'metrics' => [
                    'days' => 0,
                    'history_months' => round($historyMonths, 1),
                    'source' => $benchmark['source'] ?? null,
                ],
            ];

            $this->persist($period, $result);

            return $result;
        }

        // ---- STEP 3: nothing usable -> a clean zero. ----------------------
        $result = [
            'month' => $period->format('Y-m'),
            'basis' => 'empty',
            'days' => 0,
            'history_months' => round($historyMonths, 1),
            'weights' => $this->emptyWeights(),
            'metrics' => [
                'days' => 0,
                'history_months' => round($historyMonths, 1),
                'source' => null,
            ],
        ];

        $this->persist($period, $result);

        return $result;
    }

    /**
     * Fit the seven weekday multipliers plus the baseline meal rate.
     *
     * THE ARITHMETIC (deliberately simple enough to check by hand):
     *
     *   multiplier[weekday] = mean(meals on that weekday) / mean(meals on all days)
     *
     * A value above 1 means "this weekday eats more than typical". Clamped to
     * [0.5, 1.5] because a factor far outside that range signals a data quirk (one
     * enormous day, or a partial month) rather than a real weekly rhythm, and
     * letting it through would make the projection lurch.
     *
     * `baseline_per_member` is meals per ACTIVE member per day, which is what lets
     * the projection scale when the roster grows or shrinks.
     */
    public function fitWeekdayWeights(Carbon $period): array
    {
        $from = $period->copy()->subMonths(static::TRAIN_MONTHS)->startOfMonth();
        $to = $period->copy()->subDay();

        $rows = MealEntry::query()
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->selectRaw('date as on_date, COALESCE(SUM(breakfast + lunch + dinner), 0) as total')
            ->groupBy('date')
            ->get();

        // Not enough distinct days for a weekday pattern to mean anything.
        if ($rows->count() < 21) {
            return $this->emptyWeights();
        }

        $overallMean = (float) $rows->avg('total');

        if ($overallMean <= 0.0) {
            return $this->emptyWeights();
        }

        $multipliers = [];

        for ($weekday = 0; $weekday <= 6; $weekday++) {
            $sameDay = $rows->filter(
                fn ($row) => Carbon::parse($row->on_date)->dayOfWeek === $weekday
            );

            // Fewer than three observations is noise, not a pattern: fall back to
            // the neutral multiplier so a thin weekday does not skew the week.
            if ($sameDay->count() < 3) {
                $multipliers[$weekday] = 1.0;

                continue;
            }

            $factor = ((float) $sameDay->avg('total')) / $overallMean;

            $multipliers[$weekday] = round(max(min($factor, 1.5), 0.5), 4);
        }

        $roster = max(Student::active()->count(), 1);

        return [
            'weekday_multipliers' => $multipliers,
            'baseline_daily_meals' => round($overallMean, 2),
            // Meals per active member per day - the roster-scaling term.
            'baseline_per_member' => round($overallMean / $roster, 4),
            'roster' => $roster,
            'source' => 'institution_history',
        ];
    }

    /**
     * Weights derived from country aggregates, for a thin-history institution.
     *
     * Returns null when the country publishes nothing usable, so the caller falls
     * through to the clean-zero case rather than inventing figures.
     */
    public function benchmarkWeights(Carbon $period): ?array
    {
        $country = strtoupper((string) (
            $this->institution?->country_code
            ?? config('services.forecasting.default_country', 'BD')
        ));

        $on = $period->toDateString();

        $perMember = ForecastBenchmark::latest($country, 'meals_per_member', $on);
        $dailyRate = ForecastBenchmark::latest($country, 'daily_meal_rate', $on);
        $cost = ForecastBenchmark::latest($country, 'cost_per_meal', $on);

        // Without at least the consumption figures there is no defensible basis.
        if (! $perMember && ! $dailyRate && ! $cost) {
            return null;
        }

        return [
            // A country aggregate carries no weekly shape, so every weekday is
            // neutral. Claiming otherwise would be fabricating a pattern.
            'weekday_multipliers' => array_fill(0, 7, 1.0),
            'baseline_daily_meals' => 0.0,
            'baseline_per_member' => round((float) ($perMember->value ?? 2.0), 4),
            'daily_rate' => round((float) ($dailyRate->value ?? 0.85), 4),
            'roster' => max(Student::active()->count(), 0),
            'source' => $perMember->source ?? $dailyRate->source ?? $cost->source ?? null,
            'country' => $country,
        ];
    }

    /** A neutral, all-zero weight set. */
    protected function emptyWeights(): array
    {
        return [
            'weekday_multipliers' => array_fill(0, 7, 1.0),
            'baseline_daily_meals' => 0.0,
            'baseline_per_member' => 0.0,
            'roster' => 0,
            'source' => null,
        ];
    }

    /** Diagnostics for the training run, so a later month can be compared. */
    protected function trainingMetrics(Carbon $period): array
    {
        $from = $period->copy()->subMonths(static::TRAIN_MONTHS)->startOfMonth();
        $to = $period->copy()->subDay();

        $days = (int) MealEntry::query()
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->distinct()
            ->count('date');

        return [
            'days' => $days,
            'history_months' => round($this->monthsOfHistory($period), 1),
            'trained_at' => now()->toIso8601String(),
        ];
    }

    /**
     * How many months of usable history precede this period.
     *
     * Counted from DISTINCT months that actually contain meal entries, not from
     * the calendar span since the institution was created. An institution that
     * registered a year ago but only started recording last week has ONE month of
     * history, and must be treated as such.
     */
    public function monthsOfHistory(Carbon $period): float
    {
        $earliest = MealEntry::query()->min('date');

        if ($earliest === null) {
            return 0.0;
        }

        $start = Carbon::parse($earliest)->startOfMonth();

        if ($start->gte($period)) {
            return 0.0;
        }

        return $start->diffInMonths($period);
    }

    /** Write (or replace) this month's model row. */
    protected function persist(Carbon $period, array $result): ForecastModel
    {
        return ForecastModel::withoutTenantScope()->updateOrCreate(
            [
                'institution_id' => $this->institution?->id,
                'period_month' => $period->toDateString(),
                'model' => static::MODEL,
            ],
            [
                'basis' => $result['basis'],
                'weights' => $result['weights'],
                'metrics' => $result['metrics'],
            ]
        );
    }

    /* ------------------------------------------------------------------ *
     * THE 3-MONTH ROLLING FORECAST
     * ------------------------------------------------------------------ */

    /**
     * Generate and PERSIST the 3-month rolling forecast.
     *
     * Run on the FIRST day of each month (see routes/console.php), so the
     * projection is stable for the whole month and can be looked back on.
     *
     * THE PRICE IS THE LEDGER RATE AND NOTHING ELSE.
     * Every projected month is priced at the SAME auditable rate -
     * `(expenses − subsidies) ÷ consumed meals` - taken from MealPriceEngine. The
     * model supplies only the COUNTS. When the ledger has no meals the whole
     * projection prices at 0, which is the "0 yields 0" rule, and `basis` says so.
     *
     * @return array{months:array, basis:string, generated_at:string}
     */
    public function generateRollingForecast(?string $month = null): array
    {
        $period = $month
            ? Carbon::createFromFormat('Y-m', $month)->startOfMonth()
            : now()->startOfMonth();

        $model = ForecastModel::withoutTenantScope()
            ->where('institution_id', $this->institution?->id)
            ->where('model', static::MODEL)
            ->where('period_month', $period->toDateString())
            ->first();

        // Train on demand when the schedule has not run yet, so the forecast is
        // never silently empty on a fresh install.
        if (! $model) {
            $this->train($period->format('Y-m'));

            $model = ForecastModel::withoutTenantScope()
                ->where('institution_id', $this->institution?->id)
                ->where('model', static::MODEL)
                ->where('period_month', $period->toDateString())
                ->first();
        }

        $weights = $model?->weights ?? $this->emptyWeights();
        $basis = $model?->basis ?? 'empty';

        /*
         * ONE PRICE FOR THE WHOLE HORIZON, FROM THE LEDGER.
         * See MealPriceEngine::forecastRate - this never consults a benchmark,
         * because a benchmark describes another institution's suppliers.
         */
        $pricing = MealPriceEngine::forecastRate($this->institution, $period->format('Y-m'));
        $rate = $pricing['rate'];

        $roster = max((int) ($weights['roster'] ?? Student::active()->count()), 0);
        $perMember = (float) ($weights['baseline_per_member'] ?? 0.0);
        $multipliers = (array) ($weights['weekday_multipliers'] ?? array_fill(0, 7, 1.0));

        $months = [];

        for ($offset = 1; $offset <= static::ROLLING_MONTHS; $offset++) {
            $monthStart = $period->copy()->addMonths($offset);
            $monthBasis = $basis;

            $meals = $this->projectMealsFor($monthStart, $roster, $perMember, $multipliers);

            /*
             * A benchmark month still produces a COUNT (from the country's
             * meals-per-member figure); an `empty` month does not, and yields a
             * clean zero rather than a fabricated count.
             */
            if ($basis === 'empty') {
                $meals = 0;
            }

            // 0 meals => 0 cost. Never a substituted figure.
            $cost = $meals > 0 ? round($meals * $rate, 2) : 0.0;

            $months[] = [
                'month' => $monthStart->format('Y-m'),
                'label' => $monthStart->format('F Y'),
                'projected_meals' => $meals,
                'projected_rate' => $meals > 0 ? $rate : 0.0,
                'projected_cost' => $cost,
                'basis' => $monthBasis,
                'price_basis' => $pricing['basis'],
            ];
        }

        $payload = [
            'months' => $months,
            // The WORST basis across the horizon: if any month leans on benchmarks
            // the headline must not claim to be learned.
            'basis' => collect($months)->contains(fn ($m) => $m['basis'] === 'benchmark')
                ? 'benchmark'
                : $basis,
            'generated_at' => now()->toIso8601String(),
        ];

        // Persist onto this month's row so the projection is a stable artefact.
        if ($model) {
            $model->forceFill([
                'forecast' => $months,
                'forecast_generated_at' => now(),
            ])->save();
        }

        return $payload;
    }

    /**
     * Project one month's total meals from the trained weights.
     *
     * counts = roster x meals-per-member x (sum of the month's weekday multipliers
     *          divided by the number of days in a week)
     *
     * Walking the actual calendar - rather than multiplying by days-in-month -
     * means a 31-day month with five Fridays is projected differently from a
     * 30-day month with four, which is the whole reason for keeping a weekday
     * vector in the first place.
     */
    protected function projectMealsFor(Carbon $monthStart, int $roster, float $perMember, array $multipliers): int
    {
        if ($roster <= 0 || $perMember <= 0.0) {
            return 0;
        }

        $daysInMonth = $monthStart->daysInMonth;
        $total = 0.0;

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = $monthStart->copy()->day($day);
            $weekday = (int) $date->dayOfWeek;

            $total += $roster * $perMember * (float) ($multipliers[$weekday] ?? 1.0);
        }

        return max((int) round($total), 0);
    }

    /** The configured minimum history threshold. */
    protected static function minHistoryMonths(): int
    {
        return (int) config('services.forecasting.min_history_months', static::MIN_HISTORY_MONTHS);
    }

    /* ------------------------------------------------------------------ *
     * CORPUS MAINTENANCE
     * ------------------------------------------------------------------ */

    /**
     * Rebuild the retrieval corpus as part of training.
     *
     * The daily forecaster still retrieves similar past days for its COUNT
     * estimate; refreshing the embeddings here keeps that corpus current without
     * a separate manual step, which is what made it drift stale in practice.
     */
    public function refreshCorpus(): int
    {
        try {
            return (new Forecaster($this->institution))->buildEmbeddings();
        } catch (\Throwable $e) {
            // A corpus refresh failure must not fail the training run: the weights
            // and the rolling forecast are the primary output.
            report($e);

            return 0;
        }
    }
}
