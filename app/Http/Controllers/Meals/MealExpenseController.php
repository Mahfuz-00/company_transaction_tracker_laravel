<?php

namespace App\Http\Controllers\Meals;

use App\Http\Controllers\Controller;
use App\Models\MealExpense;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class MealExpenseController extends Controller
{

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $category = (string) $request->query('category', '');
        $from = $request->query('from');
        $to = $request->query('to');

        $expenses = MealExpense::query()
            ->with(['transaction:id,amount,category', 'recorder:id,name'])
            ->when($category !== '', fn ($q) => $q->where('category', $category))
            ->when($search !== '', function ($q) use ($search) {
                $term = '%'.$search.'%';
                $q->where(fn ($sub) => $sub->where('description', 'like', $term)
                    ->orWhere('category', 'like', $term));
            })
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $filteredTotal = MealExpense::query()
            ->when($category !== '', fn ($q) => $q->where('category', $category))
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->join('transactions', 'transactions.id', '=', 'meal_expenses.transaction_id')
            ->sum('transactions.amount');

        return Inertia::render('Meals/Expenses/Index', [
            'expenses' => $expenses,
            'categories' => MealExpense::query()
                ->whereNotNull('category')
                ->distinct()
                ->orderBy('category')
                ->pluck('category'),
            'filteredTotal' => (float) $filteredTotal,
            'filters' => [
                'search' => $search,
                'category' => $category,
                'from' => $from,
                'to' => $to,
            ],
        ]);
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
            'notes' => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($data) {
            // transactions.user_id is NOT NULL, so it must be set.
            $tx = Transaction::create([
                'user_id' => auth()->id(),
                'type' => 'out',
                'item' => $data['description'] ?? 'Meal Expense',
                'amount' => $data['amount'],
                'category' => $data['category'] ?? 'Meal Expense',
                // Who was paid, and why - the dorm's expense metadata.
                'payee' => $data['payee'] ?? null,
                'reason' => $data['reason'] ?? $data['notes'] ?? null,
                'source' => 'meal_expense',
            ]);

            MealExpense::create([
                'transaction_id' => $tx->id,
                'description' => $data['description'] ?? null,
                'category' => $data['category'] ?? null,
                'recorded_by' => auth()->id(),
            ]);

            return redirect()
                ->route('meals.expenses.index')
                ->with('success', 'Expense recorded as a cash-out transaction.');
        });
    }
}
