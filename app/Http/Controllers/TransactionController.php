<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Support\AnalyticsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The transaction ledger - money in and money out.
 *
 * WHAT THIS IS
 * ------------
 * `transactions` is the ledger: one row per movement of money, `type` = 'in'
 * (a deposit / member contribution) or 'out' (an expense / payment). Almost
 * everything else in the app - balances, dashboards, vendor spend - is DERIVED
 * from these rows. So this controller is the read side, plus a thin legacy
 * bridge that forwards old URLs to the modern create flows.
 *
 * MONEY MATH
 * ----------
 * A row stores a POSITIVE `amount`; its SIGN is decided by `type`. Every balance
 * is therefore `sum(type = 'in') - sum(type = 'out')`. The model casts `amount`
 * to `decimal:2`, so the value arrives as a numeric STRING - fine for PHP's
 * arithmetic operators, but never assume it is an int/float when comparing.
 *
 * TENANCY
 * -------
 * `Transaction` uses `BelongsToInstitution`, so every query here is implicitly
 * filtered to the active institution by the global scope. The
 * `where('user_id', ...)` clauses narrow further, to the logged-in user's own
 * rows - both conditions apply together.
 */
class TransactionController extends Controller
{
    /**
     * The dashboard: balances, a filtered/paginated ledger list, and this month's
     * totals.
     *
     * These are computed server-side rather than in the view because they need
     * SQL AGGREGATES over the whole table, not just the rows on the current page:
     *  - the OVERALL balance (all-time, deliberately NOT affected by the
     *    filters), so the headline figure does not jump around while the user
     *    experiments with filtering;
     *  - the filtered, paginated list, annotated with a RUNNING balance;
     *  - the current calendar month's in/out totals.
     */
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
        // The page is newest-first (created_at desc), but a running balance must
        // accumulate OLDEST-first - so reverse() before mapping, then reverse
        // again afterwards to restore the newest-first order the user expects.
        $transformed = $collection->reverse()->map(function ($t) use (&$balance) {
            // Rows store a positive amount; the SIGN comes from the type, so
            // money-out subtracts. This is what turns the raw ledger into a
            // running balance.
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
     * The Analytics dashboard.
     *
     * All query logic lives in AnalyticsService; this method is a thin HTTP layer
     * that resolves the user and hands off. (The method used to be ~220 lines -
     * extracted so the controller stays readable and the logic is testable.)
     */
    public function analytics(Request $request)
    {
        $data = app(AnalyticsService::class)->build($request, $request->user()->id);

        return Inertia::render('Analytics', $data);
    }
}
