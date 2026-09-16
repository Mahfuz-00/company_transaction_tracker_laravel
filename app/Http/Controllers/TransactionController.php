<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use App\Models\MealEntry;
use App\Models\MealRateSetting;
use App\Models\Student;
use App\Models\Subsidy;
use App\Models\SubsidySource;
use App\Models\Transaction;
use App\Support\FinanceCalculator;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()->id;

        // compute overall balance for the user (shouldn't be affected by filters)
        $totalInOverall = Transaction::where('user_id', $userId)->where('type', 'in')->sum('amount');
        $totalOutOverall = Transaction::where('user_id', $userId)->where('type', 'out')->sum('amount');
        $overallBalance = $totalInOverall - $totalOutOverall;

        $query = Transaction::where('user_id', $userId);

        // Filters
        if ($request->filled('type') && in_array($request->type, ['in', 'out'])) {
            $query->where('type', $request->type);
        }

        // Date range filter
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Amount range filter
        if ($request->filled('amount_min')) {
            $query->where('amount', '>=', $request->amount_min);
        }

        if ($request->filled('amount_max')) {
            $query->where('amount', '<=', $request->amount_max);
        }

        // Search across item and by_whom
        if ($request->filled('search')) {
            $q = $request->search;
            $query->where(function ($sub) use ($q) {
                $sub->where('item', 'like', "%{$q}%")
                    ->orWhere('by_whom', 'like', "%{$q}%");
            });
        }

        // Sorting: amount asc/desc or default by created_at desc
        if ($request->filled('sort_amount') && in_array(strtolower($request->sort_amount), ['asc', 'desc'])) {
            $query->orderBy('amount', $request->sort_amount);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        // Pagination
        // support 'limit' as alias for per_page
        $perPage = (int) $request->input('per_page', $request->input('limit', 10));
        $paginator = $query->paginate($perPage)->withQueryString();

        // Calculate running balance for the displayed (filtered) list
        $balance = 0;

        $collection = $paginator->getCollection();
        // collection comes newest-first (created_at desc); compute running balance oldest-first
        $transformed = $collection->reverse()->map(function ($t) use (&$balance) {
            $effectiveAmount = $t->type === 'in' ? $t->amount : -$t->amount;
            $balance += $effectiveAmount;

            return [
                'id' => $t->id,
                'item' => $t->item,
                'type' => $t->type,
                'amount' => $effectiveAmount,
                'running_balance' => $balance,
                'created_at' => $t->created_at->format('Y-m-d H:i'),
                'category' => $t->category ?? null,
                'payment_method' => $t->payment_method ?? 'Cash',
                'by_whom' => $t->by_whom ?? null,
            ];
        })->reverse()->values();

        // replace paginator collection with transformed items
        $paginator->setCollection($transformed);

        // this month's totals (compute here so dashboard can display them)
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        $monthIncome = Transaction::where('user_id', $userId)
            ->where('type', 'in')
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->sum('amount');

        $monthExpense = Transaction::where('user_id', $userId)
            ->where('type', 'out')
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->sum('amount');

        return Inertia::render('Dashboard', [
            'transactions' => $paginator,
            // pass the overall balance (not affected by filters)
            'currentBalance' => $overallBalance,
            'monthIncome' => $monthIncome ?? 0,
            'monthExpense' => $monthExpense ?? 0,
        ]);
    }

    /**
     * The standalone "Add Transaction" screen is gone.
     *
     * Money in is a DEPOSIT and money out is an EXPENSE - each now lives in its
     * own purpose-built module (meals.deposits / meals.expenses) with the right
     * fields, validation and side effects. Anyone hitting the old URL is sent to
     * the matching module instead of a generic form.
     */
    public function create(Request $request)
    {
        $type = $request->query('type', 'out');

        return $type === 'in'
            ? redirect()->route('meals.deposits.index')
            : redirect()->route('meals.expenses.index');
    }

    /**
     * Kept as a thin bridge so any legacy bookmark still works: an "in" posts a
     * deposit, an "out" posts an expense, through the dedicated modules.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'item' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:in,out'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'category' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['nullable', 'string', 'max:100'],
            'student_id' => ['nullable', 'exists:students,id', 'required_if:type,in'],
            'payee' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validated['type'] === 'in') {
            return app(\App\Http\Controllers\Meals\DepositController::class)
                ->store($request->merge([
                    'student_id' => $validated['student_id'],
                    'amount' => $validated['amount'],
                    'payment_method' => $validated['payment_method'] ?? null,
                    'notes' => $validated['reason'] ?? null,
                ]));
        }

        return app(\App\Http\Controllers\Meals\MealExpenseController::class)
            ->store($request->merge([
                'description' => $validated['item'],
                'amount' => $validated['amount'],
                'category' => $validated['category'] ?? null,
                'notes' => $validated['reason'] ?? null,
            ]));
    }

    /**
     * Analytics: month-scoped finance figures, subsidy tracking, and the
     * three-month predictive forecast.
     *
     * Reports default to the CURRENT MONTH (no arbitrary date ranges); a month
     * can be selected explicitly, and a couple of preset ranges remain for
     * convenience.
     */
    public function analytics(Request $request)
    {
        $userId = $request->user()->id;
        $finance = new FinanceCalculator();

        // Resolve the reporting window. A concrete month always wins; the
        // preset ranges are derived from it.
        $period = $request->input('period');
        $month = FinanceCalculator::resolveMonth($request->input('month'));
        $now = Carbon::now();

        if ($request->filled('from') && $request->filled('to')) {
            $start = Carbon::parse($request->input('from'))->startOfDay();
            $end = Carbon::parse($request->input('to'))->endOfDay();
            $activePeriod = 'custom';
        } else {
            switch ($period) {
                case 'last_month':
                    $start = $now->copy()->subMonthNoOverflow()->startOfMonth();
                    $end = $now->copy()->subMonthNoOverflow()->endOfMonth();
                    $activePeriod = 'last_month';
                    break;
                case 'last_3_months':
                    $start = $now->copy()->subMonths(3)->startOfMonth();
                    $end = $now->copy()->endOfMonth();
                    $activePeriod = 'last_3_months';
                    break;
                case 'ytd':
                    $start = $now->copy()->startOfYear();
                    $end = $now->copy()->endOfDay();
                    $activePeriod = 'ytd';
                    break;
                case 'current_month':
                default:
                    [$start, $end] = FinanceCalculator::monthBounds($month);
                    $activePeriod = 'current_month';
                    break;
            }
        }

        // Ensure $start and $end are defined
        if (!isset($start) || !isset($end)) {
            [$start, $end] = FinanceCalculator::monthBounds($month);
            $activePeriod = 'current_month';
        }

        // The month snapshot drives every derived figure on this screen.
        $monthSnapshot = $finance->monthSnapshot($month);

        // Totals for the selected period
        $totalIn = Transaction::where('user_id', $userId)
            ->where('type', 'in')
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount');

        $totalOut = Transaction::where('user_id', $userId)
            ->where('type', 'out')
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount');

        $netBalance = $totalIn - $totalOut;

        // Current overall balance (all time)
        $totalInOverall = Transaction::where('user_id', $userId)->where('type', 'in')->sum('amount');
        $totalOutOverall = Transaction::where('user_id', $userId)->where('type', 'out')->sum('amount');
        $currentBalance = $totalInOverall - $totalOutOverall;

        // Previous period (for percent change): compute same-length previous span
        $periodDays = $start->diffInDays($end) + 1;
        $prevEnd = $start->copy()->subDay();
        $prevStart = $prevEnd->copy()->subDays($periodDays - 1)->startOfDay();

        $prevIn = Transaction::where('user_id', $userId)
            ->where('type', 'in')
            ->whereBetween('created_at', [$prevStart, $prevEnd])
            ->sum('amount');

        $prevOut = Transaction::where('user_id', $userId)
            ->where('type', 'out')
            ->whereBetween('created_at', [$prevStart, $prevEnd])
            ->sum('amount');

        // Decide grouping granularity: if range <= 31 days use daily, else monthly
        $useDaily = ($start->diffInDays($end) <= 31);
        $driver = DB::connection()->getDriverName();
        if ($useDaily) {
            $groupExpr = $driver === 'sqlite' ? "strftime('%Y-%m-%d', created_at)" : "DATE_FORMAT(created_at, '%Y-%m-%d')";
        } else {
            $groupExpr = $driver === 'sqlite' ? "strftime('%Y-%m', created_at)" : "DATE_FORMAT(created_at, '%Y-%m')";
        }

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

        // --- Dorm-wide figures (the pool is shared, not per-user) ---------
        $dormIn = (float) Transaction::query()->deposits()
            ->whereBetween('created_at', [$start, $end])->sum('amount');
        $dormOut = (float) Transaction::query()->expenses()
            ->whereBetween('created_at', [$start, $end])->sum('amount');

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

        // Meals per day (or per month for long ranges) so the trend chart lines
        // up with the money trend chart.
        $mealGroupExpr = $useDaily
            ? ($driver === 'sqlite' ? "strftime('%Y-%m-%d', date)" : 'DATE(date)')
            : ($driver === 'sqlite' ? "strftime('%Y-%m', date)" : "DATE_FORMAT(date, '%Y-%m')");

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

        // Largest individual outgoings, with payee context.
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

        return Inertia::render('Analytics', [
            'totalIn' => (float)$totalIn,
            'totalOut' => (float)$totalOut,
            'netBalance' => (float)$netBalance,
            'currentBalance' => (float)$currentBalance,
            'monthlySummary' => $monthlySummary,
            'dorm' => [
                'deposits' => $dormIn,
                'expenses' => $dormOut,
                'pool_balance' => round($dormIn - $dormOut, 2),
                'meals' => $mealsInRange,
                'breakfast' => (int) $mealByType->breakfast,
                'lunch' => (int) $mealByType->lunch,
                'dinner' => (int) $mealByType->dinner,
                // The per-meal rate now comes from the shared calculator, so it
                // is always expense / meals for the selected month.
                'cost_per_meal' => $finance->perMealRate($month),
                'active_students' => Student::active()->count(),
                // Subsidy money tracked separately from member deposits.
                'subsidies' => $finance->subsidiesForMonth($month),
                'subsidy_coverage_pct' => $monthSnapshot['subsidy_coverage_pct'],
            ],
            'mealTrend' => $mealTrend,
            'expenseByCategory' => $expenseByCategory,
            'topExpenses' => $topExpenses,
            'grouping' => $useDaily ? 'daily' : 'monthly',

            /* ---------------------------------------------------------- *
             * Month + subsidy tracking + forecast
             * ---------------------------------------------------------- */
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
                'totalIn' => (float)$prevIn,
                'totalOut' => (float)$prevOut,
            ],
        ]);
    }

    /**
     * Subsidy metrics for a month: totals per funding source, each source's
     * share of the pool, and how much of the meal cost is covered.
     */
    protected function subsidyTracking(string $month): array
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
                    // Actual share of this month's subsidy money.
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
    protected function monthOptions(): array
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