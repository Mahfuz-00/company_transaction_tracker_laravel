<?php

namespace App\Support;

use App\Models\Deposit;
use App\Models\Institution;
use App\Models\MealEntry;
use App\Models\MealRateSetting;
use App\Models\Refund;
use App\Models\Student;
use App\Models\Subsidy;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The finance brain of the system.
 *
 * Every money figure the app shows - per-meal rate, member balances, subsidy
 * coverage, and the forward forecast - is derived here from two sources of
 * truth:
 *
 *   expenses  : `transactions` where type = out   (Cash Out)
 *   meals     : SUM(breakfast + lunch + dinner) on `meal_entries`
 *
 * The central identity is:
 *
 *     per-meal rate = total expense / total meals
 *
 * Everything else (a member's cost, what a subsidy covers, next month's
 * projection) is expressed in terms of that one number, so the modules can
 * never disagree with each other.
 */
class FinanceCalculator
{
    public function __construct(
        protected ?Institution $institution = null,
    ) {
        $this->institution = $institution ?? Institution::current();
    }

    /**
     * Run a figure computation pinned to THIS calculator's institution.
     *
     * The calculator is used both in normal scoped requests (where the active
     * tenant already equals the institution) and in the Software Super Admin's
     * registry, which computes several institutions in one request while the
     * manager may be global. Pinning here - via withTenant, which always restores
     * the previous context - guarantees each figure is that institution's own,
     * with no leakage into the surrounding query stream.
     */
    protected function scoped(callable $callback): mixed
    {
        $tenantId = $this->institution?->id;

        // No institution (a fresh install): run as-is under the active context.
        if ($tenantId === null) {
            return $callback();
        }

        return app(\App\Support\TenantManager::class)->withTenant((int) $tenantId, $callback);
    }

    /* ------------------------------------------------------------------ *
     * Period helpers
     * ------------------------------------------------------------------ */

    /** [firstDay, lastDay] Carbon bounds for a YYYY-MM month. */
    public static function monthBounds(string $month): array
    {
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();

        return [$start, $start->copy()->endOfMonth()];
    }

    /** Validate a YYYY-MM string, defaulting to the current month. */
    public static function resolveMonth(?string $month): string
    {
        return (is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month))
            ? $month
            : now()->format('Y-m');
    }

    /* ------------------------------------------------------------------ *
     * Core aggregates
     * ------------------------------------------------------------------ */

    /** Total meals eaten in a month. */
    public function mealsForMonth(string $month): int
    {
        return $this->scoped(function () use ($month) {
            [$start, $end] = self::monthBounds($month);

            return (int) MealEntry::query()
                ->whereDate('date', '>=', $start->toDateString())
                ->whereDate('date', '<=', $end->toDateString())
                ->selectRaw('COALESCE(SUM(breakfast + lunch + dinner), 0) as total')
                ->value('total');
        });
    }

    /** Total expense (Cash Out) in a month. Subsidy reversals are excluded. */
    public function expensesForMonth(string $month): float
    {
        return $this->scoped(function () use ($month) {
            [$start, $end] = self::monthBounds($month);

            return (float) Transaction::query()
                ->where('type', 'out')
                // A member REFUND is money returned from the wallet, not a mess
                // expense - counting it would inflate the per-meal rate. Exclude
                // the refund's cash-out (and any deposit reversal) here.
                ->whereNotIn('transactions.source', ['refund', 'deposit'])
                // An out-transaction whose meal expense was later reversed is no
                // longer a real expense - exclude it so the month total falls.
                ->where(function ($q) {
                    $q->where('transactions.source', '!=', 'meal_expense')
                        ->orWhereNotExists(function ($sub) {
                            $sub->select(DB::raw(1))
                                ->from('meal_expenses')
                                ->whereColumn('meal_expenses.transaction_id', 'transactions.id')
                                ->whereNotNull('meal_expenses.reversed_at');
                        });
                })
                ->whereDate('created_at', '>=', $start->toDateString())
                ->whereDate('created_at', '<=', $end->toDateString())
                ->sum('amount');
        });
    }

    /** Personal deposits (Cash In) in a month. */
    public function depositsForMonth(string $month): float
    {
        return $this->scoped(function () use ($month) {
            [$start, $end] = self::monthBounds($month);

            return (float) Transaction::query()
                ->where('type', 'in')
                // Reversal entries (cash-ins posted to cancel a reversed expense)
                // are not deposits - exclude them from the deposit figure. A
                // refund reversal (a cash-in that undoes a refund) is likewise not
                // a new deposit.
                ->where(function ($q) {
                    $q->whereNull('transactions.category')
                        ->orWhereNotIn('transactions.category', ['Expense Reversal', 'Refund Reversal']);
                })
                ->whereDate('created_at', '>=', $start->toDateString())
                ->whereDate('created_at', '<=', $end->toDateString())
                ->sum('amount');
        });
    }

    /** Subsidy money recorded for a month. */
    public function subsidiesForMonth(string $month): float
    {
        return $this->scoped(function () use ($month) {
            return (float) Subsidy::query()
                ->active()
                ->forMonth($month)
                ->sum('amount');
        });
    }

    /**
     * The per-meal rate for a month: expense / meals, honouring the
     * institution's rate mode (calculated / manual / hybrid).
     */
    public function perMealRate(string $month): float
    {
        return $this->scoped(function () use ($month) {
            $settings = MealRateSetting::current();

            return $settings->resolveRate(
                $this->expensesForMonth($month),
                $this->mealsForMonth($month)
            );
        });
    }

    /* ------------------------------------------------------------------ *
     * Monthly snapshot (what every report/dashboard needs)
     * ------------------------------------------------------------------ */

    /**
     * A full month's worth of derived figures in one call, so a controller
     * never issues the same aggregate twice.
     *
     * @param  int  $memberCount  active roster size, used for per-head splits
     */
    public function monthSnapshot(string $month, ?int $memberCount = null): array
    {
        $meals = $this->mealsForMonth($month);
        $expenses = $this->expensesForMonth($month);
        $deposits = $this->depositsForMonth($month);
        $subsidies = $this->subsidiesForMonth($month);

        $rate = $this->perMealRate($month);
        $mealCost = round($meals * $rate, 2);

        // How much of the meal cost the subsidy money actually covers.
        $subsidyCoverage = $mealCost > 0
            ? round(min(1, $subsidies / $mealCost) * 100, 2)
            : 0.0;

        // What members still owe the pool once their own deposits are counted.
        $memberShortfall = max(0, round($mealCost - $deposits, 2));

        // Roster size must be counted for THIS institution, not platform-wide.
        $members = $memberCount ?? $this->scoped(fn () => Student::active()->count());

        return [
            'month' => $month,
            'label' => Carbon::createFromFormat('Y-m', $month)->format('F Y'),

            'meals' => $meals,
            'expenses' => $expenses,
            'deposits' => $deposits,
            'subsidies' => $subsidies,

            'per_meal_rate' => $rate,
            'meal_cost' => $mealCost,

            // Subsidy economics.
            'subsidy_coverage_pct' => $subsidyCoverage,
            'member_funded_pct' => $mealCost > 0
                ? round(min(1, $deposits / $mealCost) * 100, 2)
                : 0.0,
            'member_shortfall' => $memberShortfall,

            // Pool position: money actually available vs money spent.
            'pool_balance' => round($deposits + $subsidies - $expenses, 2),

            // Per-head averages, handy for dashboards.
            'members' => $members,
            'meals_per_member' => $members > 0 ? round($meals / $members, 1) : 0.0,
            'cost_per_member' => $members > 0 ? round($mealCost / $members, 2) : 0.0,
            'daily_meals' => round($meals / Carbon::createFromFormat('Y-m', $month)->daysInMonth, 1),
            'daily_cost' => round(
                $mealCost / Carbon::createFromFormat('Y-m', $month)->daysInMonth,
                2
            ),
        ];
    }

    /* ------------------------------------------------------------------ *
     * Member-level figures
     * ------------------------------------------------------------------ */

    /**
     * Per-member rows for a month: meals, cost, deposits and balance, all
     * priced at the month's rate. One grouped query per aggregate.
     */
    public function memberBreakdown(string $month): Collection
    {
        [$start, $end] = self::monthBounds($month);
        $rate = $this->perMealRate($month);
        $subsidyPool = $this->subsidiesForMonth($month);

        /*
         * The three aggregates below are pinned to this calculator's institution
         * so a multi-institution caller (the SSA registry) gets EACH institution's
         * own roster, meals and deposits - never a platform-wide blend.
         */
        [$mealCounts, $depositTotals, $refundTotals, $members] = $this->scoped(function () use ($start, $end) {
            $mealCounts = MealEntry::query()
                ->whereDate('date', '>=', $start->toDateString())
                ->whereDate('date', '<=', $end->toDateString())
                ->select('student_id')
                ->selectRaw('COALESCE(SUM(breakfast), 0) as breakfast')
                ->selectRaw('COALESCE(SUM(lunch), 0) as lunch')
                ->selectRaw('COALESCE(SUM(dinner), 0) as dinner')
                ->selectRaw('COALESCE(SUM(breakfast + lunch + dinner), 0) as meals')
                ->groupBy('student_id')
                ->get()
                ->keyBy('student_id');

            // Deposits are sourced from the deposits table (not the ledger) so
            // that a reversed deposit is excluded the moment it is flagged - this
            // is what makes a reversal immediately drop the balance. Only
            // deposits that have NOT been reversed count.
            $depositTotals = Deposit::query()
                ->whereNull('reversed_at')
                ->whereDate('created_at', '>=', $start->toDateString())
                ->whereDate('created_at', '<=', $end->toDateString())
                ->select('student_id')
                ->selectRaw('COALESCE(SUM(amount), 0) as total')
                ->groupBy('student_id')
                ->get()
                ->keyBy('student_id');

            // Refunds paid back in the month - subtracted from the member's
            // credit. Reversed refunds are excluded (same rule as deposits).
            $refundTotals = Refund::query()
                ->whereNull('reversed_at')
                ->whereDate('created_at', '>=', $start->toDateString())
                ->whereDate('created_at', '<=', $end->toDateString())
                ->select('student_id')
                ->selectRaw('COALESCE(SUM(amount), 0) as total')
                ->groupBy('student_id')
                ->get()
                ->keyBy('student_id');

            $members = Student::query()
                ->with('department:id,name')
                ->orderBy('name')
                ->get();

            return [$mealCounts, $depositTotals, $refundTotals, $members];
        });

        // Split the subsidy pool evenly for a per-head contribution figure.
        $subsidyPerHead = $members->count() > 0
            ? round($subsidyPool / $members->count(), 2)
            : 0.0;

        return $members->map(function (Student $member) use ($mealCounts, $depositTotals, $refundTotals, $rate, $subsidyPerHead) {
            $row = $mealCounts->get($member->id);
            $meals = (int) ($row->meals ?? 0);
            $deposited = (float) ($depositTotals->get($member->id)->total ?? 0);
            $refunded = (float) ($refundTotals->get($member->id)->total ?? 0);

            $cost = round($meals * $rate, 2);
            // Balance is what the member still has in credit (positive) or owes
            // (negative), net of any refunds paid back to them.
            $balance = round($deposited - $refunded - $cost, 2);

            return [
                'id' => $member->id,
                'name' => $member->name,
                'roll' => $member->roll,
                'department' => $member->department?->name,
                'status' => $member->status,
                'has_account' => (bool) $member->user_id,
                'breakfast' => (int) ($row->breakfast ?? 0),
                'lunch' => (int) ($row->lunch ?? 0),
                'dinner' => (int) ($row->dinner ?? 0),
                'meals' => $meals,
                'meal_cost' => $cost,
                'deposited' => $deposited,
                'refunded' => $refunded,
                'subsidy_share' => $subsidyPerHead,
                'balance' => $balance,
                'is_due' => $balance < 0,
            ];
        });
    }

    /* ------------------------------------------------------------------ *
     * Forecasting - MOVED
     * ------------------------------------------------------------------ *
     *
     * `forecast()` (a recency-weighted linear ramp over the institution's own
     * trailing months) USED TO LIVE HERE, and powered the "3-Month Predictive
     * Forecast" panel on the Analytics pages.
     *
     * It has been REMOVED and MIGRATED to the AI Forecasting module:
     *
     *     App\Support\Forecaster::monthlyProjection()
     *
     * WHY IT MOVED (and was not merely duplicated)
     * ---------------------------------------------
     * The old method was rigid in exactly the ways that matter for forecasting:
     *
     *   - It ignored everything except the institution's own last few months, so a
     *     brand-new workspaces got a confident-looking number derived from one or
     *     two data points.
     *   - It could not learn from any other institution, so a Bangladeshi dormitory
     *     and a US corporate canteen were forecast the same way.
     *   - It had no notion of weekday patterns, seasonality or the academic
     *     calendar.
     *   - It reported no BASIS, so the UI could not distinguish a data-driven
     *     estimate from a guess.
     *
     * The replacement retrieves from vector embeddings of similar past days across
     * the platform (RAG) and, when an institution has under three months of
     * history, falls back to explicit COUNTRY BENCHMARKS - reporting which basis
     * was used so the UI can label the two differently.
     *
     * Keeping a second implementation here is how the web pages and the mobile API
     * end up showing different numbers for the same month, so this class
     * deliberately no longer knows how to forecast at all. Its job is arithmetic on
     * WHAT HAPPENED.
     */
}
