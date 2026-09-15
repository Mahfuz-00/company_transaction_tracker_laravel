<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
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

    public function create(Request $request)
    {
        return Inertia::render('AddTransaction');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'item' => 'required|string|max:255',
            'type' => 'required|in:in,out',
            'amount' => 'required|numeric|min:0.01',
            'category' => 'nullable|string|max:100',
        ]);

        $validated = array_merge($validated, $request->validate([
            'payment_method' => 'nullable|string|max:100',
            'by_whom' => 'nullable|string|max:255',
        ]));

        $request->user()->transactions()->create($validated);

        return redirect()->back()->with('success', 'Transaction logged successfully.');
    }

    public function analytics(Request $request)
    {
        $userId = $request->user()->id;

        // Determine filter range
        $period = $request->input('period');
        $from = $request->input('from');
        $to = $request->input('to');

        $now = Carbon::now();

        if ($from && $to) {
            $start = Carbon::parse($from)->startOfDay();
            $end = Carbon::parse($to)->endOfDay();
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
                    $start = $now->copy()->startOfMonth();
                    $end = $now->copy()->endOfMonth();
                    $activePeriod = 'current_month';
                    break;
            }
        }

        // Ensure $start and $end are defined
        if (!isset($start) || !isset($end)) {
            $start = $now->copy()->startOfMonth();
            $end = $now->copy()->endOfMonth();
            $activePeriod = 'current_month';
        }

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

        return Inertia::render('Analytics', [
            'totalIn' => (float)$totalIn,
            'totalOut' => (float)$totalOut,
            'netBalance' => (float)$netBalance,
            'currentBalance' => (float)$currentBalance,
            'monthlySummary' => $monthlySummary,
            'activeFilters' => [
                'period' => $activePeriod,
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
}