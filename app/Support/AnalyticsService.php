<?php

namespace App\Support;

use App\Models\Institution;
use App\Models\MealEntry;
use App\Models\Student;
use App\Models\Subsidy;
use App\Models\SubsidySource;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Builds every figure the Analytics dashboard renders.
 *
 * Extracted from TransactionController (which had grown past 450 lines with a
 * single ~220-line analytics method) so the controller is a thin HTTP layer and
 * this query logic is independently testable and reusable by reports/exports.
 *
 * The service is stateless: call `build($request)` to get the full prop array.
 */
class AnalyticsService
{
    public function __construct(
        protected FinanceCalculator $finance = new FinanceCalculator,
    ) {}

    /**
     * Assemble the complete Analytics page payload.
     *
     * @return array<string, mixed>
     */
    public function build(Request $request, int $userId): array
    {
        $finance = $this->finance;

        // Resolve the reporting window (explicit month wins; presets derive from it).
        $period = $request->input('period');
        $month = FinanceCalculator::resolveMonth($request->input('month'));
        $now = Carbon::now();

        if ($request->filled('from') && $request->filled('to')) {
            $start = Carbon::parse($request->input('from'))->startOfDay();
            $end = Carbon::parse($request->input('to'))->endOfDay();
            $activePeriod = 'custom';
        } else {
            [$start, $end, $activePeriod] = $this->resolvePeriod($period, $month, $now);
        }

        if (! isset($start) || ! isset($end)) {
            [$start, $end] = FinanceCalculator::monthBounds($month);
            $activePeriod = 'current_month';
        }

        $monthSnapshot = $finance->monthSnapshot($month);

        // --- Period totals (per-user ledger) ----------------------------
        $totalIn = (float) Transaction::where('user_id', $userId)->where('type', 'in')
            ->whereBetween('created_at', [$start, $end])->sum('amount');
        $totalOut = (float) Transaction::where('user_id', $userId)->where('type', 'out')
            ->whereBetween('created_at', [$start, $end])->sum('amount');

        // --- Overall balance (all time) ---------------------------------
        $currentBalance = (float) Transaction::where('user_id', $userId)->where('type', 'in')->sum('amount')
            - (float) Transaction::where('user_id', $userId)->where('type', 'out')->sum('amount');

        // --- Previous equal-length period (for trend chips) -------------
        $periodDays = $start->diffInDays($end) + 1;
        $prevEnd = $start->copy()->subDay();
        $prevStart = $prevEnd->copy()->subDays($periodDays - 1)->startOfDay();

        $prevIn = (float) Transaction::where('user_id', $userId)->where('type', 'in')
            ->whereBetween('created_at', [$prevStart, $prevEnd])->sum('amount');
        $prevOut = (float) Transaction::where('user_id', $userId)->where('type', 'out')
            ->whereBetween('created_at', [$prevStart, $prevEnd])->sum('amount');

        // Grouping granularity: daily for short ranges, monthly for long ones.
        $useDaily = $start->diffInDays($end) <= 31;
        $driver = DB::connection()->getDriverName();

        $groupExpr = $this->dateGroupExpr('created_at', $useDaily, $driver);

        $monthlySummary = Transaction::where('user_id', $userId)
            ->whereBetween('created_at', [$start, $end])
            ->select(
                DB::raw("SUM(CASE WHEN type = 'in' THEN amount ELSE 0 END) as income"),
                DB::raw("SUM(CASE WHEN type = 'out' THEN amount ELSE 0 END) as expense"),
                DB::raw("{$groupExpr} as period")
            )
            ->groupBy('period')
            ->orderBy('period', 'asc')
            ->get();

        // --- Institution-wide (shared) figures --------------------------
        $dormIn = (float) Transaction::query()->deposits()->whereBetween('created_at', [$start, $end])->sum('amount');
        $dormOut = (float) Transaction::query()->expenses()->whereBetween('created_at', [$start, $end])->sum('amount');

        $mealsInRange = (int) MealEntry::query()
            ->whereBetween('date', [$start, $end])
            ->selectRaw('COALESCE(SUM(breakfast + lunch + dinner), 0) as total')
            ->first()->total;

        $mealByType = MealEntry::query()
            ->whereBetween('date', [$start, $end])
            ->selectRaw('COALESCE(SUM(breakfast), 0) as breakfast')
            ->selectRaw('COALESCE(SUM(lunch), 0) as lunch')
            ->selectRaw('COALESCE(SUM(dinner), 0) as dinner')
            ->first();

        $mealGroupExpr = $this->dateGroupExpr('date', $useDaily, $driver);

        $mealTrend = MealEntry::query()
            ->whereBetween('date', [$start, $end])
            ->select(
                DB::raw("{$mealGroupExpr} as period"),
                DB::raw('COALESCE(SUM(breakfast), 0) as breakfast'),
                DB::raw('COALESCE(SUM(lunch), 0) as lunch'),
                DB::raw('COALESCE(SUM(dinner), 0) as dinner'),
                DB::raw('COALESCE(SUM(breakfast + lunch + dinner), 0) as total')
            )
            ->groupBy('period')
            ->orderBy('period', 'asc')
            ->get();

        // Where the money went, by category.
        $expenseByCategory = Transaction::query()
            ->expenses()
            ->whereBetween('created_at', [$start, $end])
            ->select('category', DB::raw('COALESCE(SUM(amount), 0) as total'))
            ->groupBy('category')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'category' => $row->category ?: 'Uncategorised',
                'total' => (float) $row->total,
            ]);

        // Largest individual outgoings.
        $topExpenses = Transaction::query()
            ->expenses()
            ->with('user:id,name')
            ->whereBetween('created_at', [$start, $end])
            ->orderByDesc('amount')
            ->limit(8)
            ->get()
            ->map(fn (Transaction $tx) => [
                'id' => $tx->id,
                'item' => $tx->item,
                'payee' => $tx->payee,
                'category' => $tx->category,
                'amount' => (float) $tx->amount,
                'date' => $tx->created_at->format('M j, Y'),
            ]);

        return [
            'totalIn' => $totalIn,
            'totalOut' => $totalOut,
            'netBalance' => $totalIn - $totalOut,
            'currentBalance' => $currentBalance,
            'monthlySummary' => $monthlySummary,
            'dorm' => [
                'deposits' => $dormIn,
                'expenses' => $dormOut,
                'pool_balance' => round($dormIn - $dormOut, 2),
                'meals' => $mealsInRange,
                'breakfast' => (int) $mealByType->breakfast,
                'lunch' => (int) $mealByType->lunch,
                'dinner' => (int) $mealByType->dinner,
                'cost_per_meal' => $finance->perMealRate($month),
                'active_students' => Student::active()->count(),
                'subsidies' => $finance->subsidiesForMonth($month),
                'subsidy_coverage_pct' => $monthSnapshot['subsidy_coverage_pct'],
            ],
            'mealTrend' => $mealTrend,
            'expenseByCategory' => $expenseByCategory,
            'topExpenses' => $topExpenses,
            'grouping' => $useDaily ? 'daily' : 'monthly',
            'month' => $month,
            'months' => $this->monthOptions(),
            'monthSnapshot' => $monthSnapshot,
            'subsidyTracking' => $this->subsidyTracking($month),
            'forecast' => $finance->forecast(),
            'activeFilters' => [
                'period' => $activePeriod,
                'month' => $month,
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
            ],
            'previousPeriod' => [
                'from' => $prevStart->toDateString(),
                'to' => $prevEnd->toDateString(),
                'totalIn' => $prevIn,
                'totalOut' => $prevOut,
            ],
        ];
    }

    /**
     * Resolve the preset range into [start, end, periodLabel].
     *
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    protected function resolvePeriod(?string $period, string $month, Carbon $now): array
    {
        switch ($period) {
            case 'last_month':
                return [
                    $now->copy()->subMonthNoOverflow()->startOfMonth(),
                    $now->copy()->subMonthNoOverflow()->endOfMonth(),
                    'last_month',
                ];
            case 'last_3_months':
                return [
                    $now->copy()->subMonths(3)->startOfMonth(),
                    $now->copy()->endOfMonth(),
                    'last_3_months',
                ];
            case 'ytd':
                return [$now->copy()->startOfYear(), $now->copy()->endOfDay(), 'ytd'];
            case 'current_month':
            default:
                [$start, $end] = FinanceCalculator::monthBounds($month);

                return [$start, $end, 'current_month'];
        }
    }

    /**
     * A database-portable date-grouping expression.
     */
    protected function dateGroupExpr(string $column, bool $daily, string $driver): string
    {
        if ($driver === 'sqlite') {
            return $daily
                ? "strftime('%Y-%m-%d', {$column})"
                : "strftime('%Y-%m', {$column})";
        }

        return $daily
            ? "DATE_FORMAT({$column}, '%Y-%m-%d')"
            : "DATE_FORMAT({$column}, '%Y-%m')";
    }

    /**
     * Subsidy metrics for a month: totals per funding source, each source's
     * share of the pool, and how much of the meal cost is covered.
     */
    public function subsidyTracking(string $month): array
    {
        $institution = Institution::current();
        SubsidySource::ensureDefaults($institution?->id);

        $bySource = Subsidy::query()
            ->active()
            ->forMonth($month)
            ->selectRaw('source, COALESCE(SUM(amount), 0) as total, COUNT(*) as entries')
            ->groupBy('source')
            ->get()
            ->keyBy('source');

        $total = (float) $bySource->sum('total');

        $sources = SubsidySource::query()
            ->where(fn ($q) => $q->whereNull('institution_id')
                ->orWhere('institution_id', $institution?->id))
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function (SubsidySource $s) use ($bySource, $total) {
                $sourceTotal = (float) ($bySource[$s->key]->total ?? 0);

                return [
                    'name' => $s->name,
                    'key' => $s->key,
                    'total' => $sourceTotal,
                    'entries' => (int) ($bySource[$s->key]->entries ?? 0),
                    'target_percentage' => (float) ($s->percentage ?? 0),
                    'actual_percentage' => $total > 0
                        ? round(($sourceTotal / $total) * 100, 2)
                        : 0.0,
                ];
            })
            ->values();

        return [
            'total' => $total,
            'sources' => $sources,
            'entries' => (int) $bySource->sum('entries'),
        ];
    }

    /** The last 18 months, for the month selector. */
    public function monthOptions(): array
    {
        $options = [];
        $cursor = now()->startOfMonth();

        for ($i = 0; $i < 18; $i++) {
            $options[] = [
                'value' => $cursor->format('Y-m'),
                'label' => $cursor->format('F Y'),
                'current' => $i === 0,
            ];
            $cursor->subMonth();
        }

        return $options;
    }
}
