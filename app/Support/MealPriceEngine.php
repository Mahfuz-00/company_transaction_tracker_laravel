<?php

namespace App\Support;

use App\Models\Institution;
use App\Models\MealEntry;
use App\Models\MealExpense;
use App\Models\Subsidy;
use Carbon\Carbon;

/**
 * THE MEAL PRICE ENGINE.
 *
 * THE ONE FORMULA THIS CLASS EXISTS TO ENFORCE
 * -------------------------------------------
 *
 *            Total Expenses − Total Subsidies
 *   Rate  =  ──────────────────────────────────
 *              Total Consumed Meals
 *
 * Meal PRICE is not a prediction. It is an ACCOUNTING IDENTITY derived from the
 * ledger, and the platform's whole settlement model depends on every figure being
 * computed the same way.
 *
 * WHY THE FORECASTER NO LONGER GUESSES IT
 * ---------------------------------------
 * The forecasting engine previously derived cost-per-meal through daily similarity
 * retrieval — averaging the `cost_per_meal` of twelve "similar" past days. That was
 * wrong for three reasons:
 *
 *   1. PRICE IS NOT STOCHASTIC. A mess that spent 45,000 on 1,000 meals has a rate
 *      of 45.00. Averaging historical rates produces a number that corresponds to
 *      no actual period of the ledger, and it visibly disagreed with the figure on
 *      the reports page — which is computed correctly from the same data.
 *
 *   2. SUBSIDIES MUST REDUCE THE MEMBER-BORNE PRICE. Retrieval ignored the subsidy
 *      netting entirely, so the forecast overstated what members would actually owe.
 *
 *   3. IT CANNOT BE AUDITED. "Why is this 43.72?" had no answer beyond "these
 *      twelve days were similar". The formula can be checked by hand from the
 *      expense and meal tables, which is what a finance feature must allow.
 *
 * Retrieval is still used — but ONLY for meal COUNTS, which genuinely do vary with
 * weekday, season and roster. That is the part that deserves a model. Price does
 * not, so it is arithmetic.
 *
 * SCOPE: one institution, one calendar month.
 */
class MealPriceEngine
{
    /**
     * The ledger rate for a month: (expenses − subsidies) ÷ consumed meals.
     *
     * Returns 0.0 when the month has no meals, which is the "0 yields 0" rule —
     * we never substitute a manual figure or a stale average for an empty month.
     *
     * @return float the member-borne cost of ONE meal, to 4 decimal places
     */
    public static function rateForMonth(string $month, ?Institution $institution = null): float
    {
        $totals = static::totalsForMonth($month, $institution);

        if ($totals['meals'] <= 0) {
            return 0.0;
        }

        // Subsidies are subtracted BEFORE dividing: a subsidy reduces what the
        // members themselves must cover, which is the rate that actually matters.
        $netExpense = max(0.0, $totals['expenses'] - $totals['subsidies']);

        return round($netExpense / $totals['meals'], 4);
    }

    /**
     * The raw components behind the rate, so a UI can SHOW the arithmetic rather
     * than just the answer. A number nobody can audit is a number nobody trusts.
     *
     * @return array{month:string,expenses:float,subsidies:float,net_expense:float,meals:int,rate:float,members:int}
     */
    public static function totalsForMonth(string $month, ?Institution $institution = null): array
    {
        $institution ??= Institution::current();

        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        /*
         * Expenses: only NON-REVERSED rows count. A reversed expense was cancelled
         * and would otherwise inflate the numerator, making every member's rate
         * wrong for the whole month.
         *
         * `created_at` is the only date an expense carries (there is no
         * `expense_date` column), so the month filter applies to it.
         */
        $expenses = (float) MealExpense::query()
            ->when($institution, fn ($q) => $q->where('institution_id', $institution->id))
            ->whereYear('created_at', $start->year)
            ->whereMonth('created_at', $start->month)
            ->whereNull('reversed_at')
            ->sum('amount');

        /*
         * Subsidies: only ACTIVE rows for the period. A cancelled subsidy must not
         * reduce the member-borne price.
         */
        $subsidies = (float) Subsidy::query()
            ->when($institution, fn ($q) => $q->where('institution_id', $institution->id))
            ->where('period_month', $start->format('Y-m'))
            ->where('status', 'active')
            ->sum('amount');

        /*
         * Meals: every breakfast/lunch/dinner actually eaten in the month. Counted
         * from the entries themselves so the denominator is the REAL consumption,
         * not a roster estimate — an absent member eats nothing and must not dilute
         * everyone else's rate.
         */
        $meals = (int) MealEntry::query()
            ->when($institution, fn ($q) => $q->where('institution_id', $institution->id))
            ->whereYear('date', $start->year)
            ->whereMonth('date', $start->month)
            ->selectRaw('COALESCE(SUM(breakfast + lunch + dinner), 0) as total')
            ->value('total');

        return [
            'month' => $start->format('Y-m'),
            'expenses' => round($expenses, 2),
            'subsidies' => round($subsidies, 2),
            'net_expense' => round(max(0.0, $expenses - $subsidies), 2),
            'meals' => $meals,
            // 0 meals => 0 rate. Never an estimate, never a manual override.
            'rate' => $meals > 0 ? round(max(0.0, $expenses - $subsidies) / $meals, 4) : 0.0,
            'members' => (int) $institution?->students()->where('status', 'active')->count(),
        ];
    }

    /**
     * The rate a FORECAST should price its predicted meals at.
     *
     * PREFERENCE ORDER (first usable wins):
     *   1. The CURRENT month's ledger rate, if it has meals. This is the most
     *      relevant price: it reflects today's suppliers and today's subsidy.
     *   2. The most recent COMPLETED month that had meals. A new month often has
     *      no entries yet on the 1st, so falling back one month avoids reporting 0
     *      for a mess that plainly has a real price.
     *   3. 0.0 — reported cleanly as zero, with the UI labelling the basis.
     *
     * Note that this NEVER consults `forecast_benchmarks`. Those exist for the
     * COUNT fallback (how many meals does a dormitory of this size eat); they are
     * not a price for THIS institution's ledger, and substituting one would
     * misstate what members owe.
     */
    public static function forecastRate(?Institution $institution = null, ?string $fromMonth = null): array
    {
        $institution ??= Institution::current();
        $cursor = $fromMonth
            ? Carbon::createFromFormat('Y-m', $fromMonth)->startOfMonth()
            : now()->startOfMonth();

        // Look back up to six months for the most recent month with real meals.
        for ($i = 0; $i < 6; $i++) {
            $month = $cursor->copy()->subMonths($i)->format('Y-m');
            $totals = static::totalsForMonth($month, $institution);

            if ($totals['meals'] > 0) {
                return [
                    'rate' => $totals['rate'],
                    'basis' => $i === 0 ? 'current_month_ledger' : 'last_month_with_meals',
                    'source_month' => $month,
                    'expenses' => $totals['expenses'],
                    'subsidies' => $totals['subsidies'],
                    'meals' => $totals['meals'],
                ];
            }
        }

        return [
            'rate' => 0.0,
            'basis' => 'no_ledger_data',
            'source_month' => null,
            'expenses' => 0.0,
            'subsidies' => 0.0,
            'meals' => 0,
        ];
    }

    /**
     * A month-by-month ledger rate series, for the forecasting history chart.
     *
     * @return array<int, array{month:string,label:string,rate:float,meals:int,expenses:float,subsidies:float}>
     */
    public static function rateHistory(int $months = 6, ?Institution $institution = null): array
    {
        $institution ??= Institution::current();
        $series = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $month = now()->subMonths($i)->format('Y-m');
            $totals = static::totalsForMonth($month, $institution);

            $series[] = [
                'month' => $month,
                'label' => now()->subMonths($i)->format('F Y'),
                'rate' => $totals['rate'],
                'meals' => $totals['meals'],
                'expenses' => $totals['expenses'],
                'subsidies' => $totals['subsidies'],
            ];
        }

        return $series;
    }
}
