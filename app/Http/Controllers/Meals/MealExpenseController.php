<?php

namespace App\Http\Controllers\Meals;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\MealExpense;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Support\AuditLogger;
use App\Support\FinanceCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class MealExpenseController extends Controller
{

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $category = (string) $request->query('category', '');
        $from = $request->query('from');
        $to = $request->query('to');

        // Expenses are month-scoped by default, matching every other module.
        $month = FinanceCalculator::resolveMonth($request->query('month'));
        [$start, $end] = FinanceCalculator::monthBounds($month);
        $vendorId = (string) $request->query('vendor', '');

        $expenses = MealExpense::query()
            ->with([
                'transaction:id,amount,category,payee,payment_method,reason',
                'vendor:id,name,slug,is_institution_hub',
                'recorder:id,name',
                'reverser:id,name',
            ])
            ->when($category !== '', fn ($q) => $q->where('category', $category))
            ->when($vendorId !== '', fn ($q) => $q->where('vendor_id', $vendorId))
            ->when($search !== '', function ($q) use ($search) {
                $term = '%'.$search.'%';
                $q->where(fn ($sub) => $sub->where('description', 'like', $term)
                    ->orWhere('category', 'like', $term)
                    ->orWhereHas('vendor', fn ($v) => $v->where('name', 'like', $term)));
            })
            ->when($start, fn ($q) => $q->whereDate('meal_expenses.created_at', '>=', $start->toDateString()))
            ->when($end, fn ($q) => $q->whereDate('meal_expenses.created_at', '<=', $end->toDateString()))
            ->orderByDesc('meal_expenses.created_at')
            ->paginate(20)
            ->withQueryString();

        // Every column is table-qualified: the query joins `transactions`, and
        // both tables carry a `created_at`, so an unqualified column is
        // ambiguous and SQLite rejects it.
        $filteredTotal = MealExpense::query()
            ->join('transactions', 'transactions.id', '=', 'meal_expenses.transaction_id')
            ->when($category !== '', fn ($q) => $q->where('meal_expenses.category', $category))
 ->when($vendorId !== '', fn ($q) => $q->where('meal_expenses.vendor_id', $vendorId))
            // Reversed expenses no longer count toward the month total.
            ->whereNull('meal_expenses.reversed_at')
            ->when($start, fn ($q) => $q->whereDate('meal_expenses.created_at', '>=', $start->toDateString()))
            ->when($end, fn ($q) => $q->whereDate('meal_expenses.created_at', '<=', $end->toDateString()))
            ->sum('transactions.amount');

        // Spend per vendor for the month, so recurring shopping is visible.
        $byVendor = MealExpense::query()
            ->when($start, fn ($q) => $q->whereDate('meal_expenses.created_at', '>=', $start->toDateString()))
            ->when($end, fn ($q) => $q->whereDate('meal_expenses.created_at', '<=', $end->toDateString()))
            // Reversed rows are excluded from vendor spend too.
            ->whereNull('meal_expenses.reversed_at')
            ->whereNotNull('meal_expenses.vendor_id')
            ->with('vendor:id,name,is_institution_hub')
            ->get()
            ->groupBy('vendor_id')
            ->map(fn ($group) => [
                'name' => $group->first()->vendor?->name ?? 'Unknown',
                'total' => round($group->sum(fn ($e) => (float) ($e->transaction?->amount ?? $e->amount ?? 0)), 2),
                'count' => $group->count(),
            ])
            ->sortByDesc('total')
            ->values();

        return Inertia::render('Meals/Expenses/Index', [
            'expenses' => $expenses,
            'categories' => MealExpense::query()
                ->whereNotNull('category')
                ->distinct()
                ->orderBy('category')
                ->pluck('category'),
            // Vendors are directly linkable from the expense form.
            'vendors' => Vendor::orderByDesc('is_institution_hub')->orderBy('name')
                ->get(['id', 'name', 'slug', 'category', 'is_institution_hub', 'recurrence']),
            'filteredTotal' => (float) $filteredTotal,
            'byVendor' => $byVendor,
            'month' => $month,
            'months' => $this->monthOptions(),
            'filters' => [
                'search' => $search,
                'category' => $category,
                'vendor' => $vendorId,
                'month' => $month,
            ],
        ]);
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

    public function create()
    {
        return redirect()->route('meals.expenses.index');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:120'],
            // Vendor is a first-class link, so recurring shopping is attributed.
            'vendor_id' => ['nullable', 'exists:vendors,id'],
            'payment_status' => ['nullable', Rule::in(['paid', 'unpaid', 'partial'])],
            'notes' => ['nullable', 'string'],
        ]);

        // Resolve the vendor so its name doubles as the payee of record.
        $vendor = ! empty($data['vendor_id']) ? Vendor::find($data['vendor_id']) : null;

        return DB::transaction(function () use ($data, $vendor) {
            // transactions.user_id is NOT NULL, so it must be set.
            $tx = Transaction::create([
                'user_id' => auth()->id(),
                'type' => 'out',
                'item' => $data['description'] ?? 'Meal Expense',
                'amount' => $data['amount'],
                'category' => $data['category'] ?? 'Meal Expense',
                // Who was paid: the linked vendor, else whatever was typed.
                'payee' => $vendor?->name ?? ($data['payee'] ?? null),
                'vendor_id' => $vendor?->id,
                'reason' => $data['reason'] ?? $data['notes'] ?? null,
                'source' => 'meal_expense',
            ]);

            MealExpense::create([
                'transaction_id' => $tx->id,
                'vendor_id' => $vendor?->id,
                'description' => $data['description'] ?? null,
                'category' => $data['category'] ?? null,
                'amount' => $data['amount'],
                'payment_status' => $data['payment_status'] ?? 'paid',
                'recorded_by' => auth()->id(),
            ]);

            AuditLogger::log('created', 'recorded an expense', $tx, [
                'description' => $data['description'] ?? null,
                'amount' => (float) $data['amount'],
                'vendor' => $vendor?->name,
            ], ['subject_label' => $data['description'] ?? 'Expense']);

            return redirect()
                ->route('meals.expenses.index')
                ->with('success', 'Expense recorded as a cash-out transaction.');
        });
    }

    /**
     * Edit an existing expense. The linked ledger transaction carries the
     * authoritative amount, so both rows are updated together.
     */
    public function update(Request $request, MealExpense $expense)
    {
        if ($expense->isReversed()) {
            return back()->with('error', 'A reversed expense cannot be edited. Record a new expense instead.');
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:120'],
            'vendor_id' => ['nullable', 'exists:vendors,id'],
            'payment_status' => ['nullable', Rule::in(['paid', 'unpaid', 'partial'])],
            // The form sends notes; the model stores them as the transaction reason.
            'notes' => ['nullable', 'string'],
        ]);

        $vendor = ! empty($data['vendor_id']) ? Vendor::find($data['vendor_id']) : null;

        return DB::transaction(function () use ($data, $vendor, $expense) {
            $before = [
                'amount' => (float) ($expense->transaction?->amount ?? $expense->amount),
                'description' => $expense->description,
                'category' => $expense->category,
                'vendor_id' => $expense->vendor_id,
                'payment_status' => $expense->payment_status,
            ];

            if ($expense->transaction) {
                $expense->transaction->update([
                    'item' => $data['description'] ?? 'Meal Expense',
                    'amount' => $data['amount'],
                    'category' => $data['category'] ?? 'Meal Expense',
                    'payee' => $vendor?->name,
                    'vendor_id' => $vendor?->id,
                    'reason' => $data['notes'] ?? null,
                ]);
            }

            $expense->update([
                'vendor_id' => $vendor?->id,
                'description' => $data['description'] ?? null,
                'category' => $data['category'] ?? null,
                'amount' => $data['amount'],
                'payment_status' => $data['payment_status'] ?? $expense->payment_status,
            ]);

            AuditLogger::log('updated', 'edited an expense', $expense, [
                'before' => $before,
                'after' => [
                    'amount' => (float) $data['amount'],
                    'description' => $data['description'] ?? null,
                    'category' => $data['category'] ?? null,
                    'vendor_id' => $vendor?->id,
                    'payment_status' => $data['payment_status'] ?? $expense->payment_status,
                ],
            ], ['subject_label' => $data['description'] ?? 'Expense']);

            return back()->with('success', 'Expense updated.');
        });
    }

    /**
     * Reverse a recorded expense: the row is kept for the audit trail but
     * flagged, and a matching cash-in is posted so the money returns to the
     * books.
     */
    public function reverse(Request $request, MealExpense $expense)
    {
        if ($expense->isReversed()) {
            return back()->with('error', 'This expense is already reversed.');
        }

        return DB::transaction(function () use ($request, $expense) {
            $amount = (float) ($expense->transaction?->amount ?? $expense->amount);

            $reversal = Transaction::create([
                'user_id' => $request->user()->id,
                'type' => 'in',
                'item' => 'Expense reversal - '.($expense->description ?: 'Meal Expense'),
                'amount' => $amount,
                'category' => 'Expense Reversal',
                'reason' => 'Reversal of expense #'.$expense->id,
                'source' => 'meal_expense',
            ]);

            $expense->update([
                'reversed_at' => now(),
                'reversed_by' => $request->user()->id,
                'reversal_transaction_id' => $reversal->id,
            ]);

            AuditLogger::log('reversed', 'reversed an expense', $expense, [
                'amount' => $amount,
                'reversal_transaction_id' => $reversal->id,
            ], ['subject_label' => $expense->description ?: 'Expense']);

            return back()->with('success', 'Expense reversed. A matching cash-in was posted.');
        });
    }
}
