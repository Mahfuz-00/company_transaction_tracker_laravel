<?php

namespace App\Http\Controllers\Meals;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\MealExpense;
use App\Models\Transaction;
use App\Models\Vendor;
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
                'transaction:id,amount,category,payee',
                'vendor:id,name,slug,is_institution_hub',
                'recorder:id,name',
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
            ->when($start, fn ($q) => $q->whereDate('meal_expenses.created_at', '>=', $start->toDateString()))
            ->when($end, fn ($q) => $q->whereDate('meal_expenses.created_at', '<=', $end->toDateString()))
            ->sum('transactions.amount');

        // Spend per vendor for the month, so recurring shopping is visible.
        $byVendor = MealExpense::query()
            ->when($start, fn ($q) => $q->whereDate('meal_expenses.created_at', '>=', $start->toDateString()))
            ->when($end, fn ($q) => $q->whereDate('meal_expenses.created_at', '<=', $end->toDateString()))
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

            return redirect()
                ->route('meals.expenses.index')
                ->with('success', 'Expense recorded as a cash-out transaction.');
        });
    }
}
