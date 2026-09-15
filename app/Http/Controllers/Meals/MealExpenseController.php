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
    public function __construct()
    {
        $this->middleware('permission:meals.expense');
    }

    public function index()
    {
        $expenses = MealExpense::with('transaction')->orderBy('created_at', 'desc')->paginate(20);
        return Inertia::render('Meals/Expenses/Index', ['expenses' => $expenses]);
    }

    public function create()
    {
        return Inertia::render('Meals/Expenses/Create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:120',
            'notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($data) {
            $tx = Transaction::create([
                'type' => 'out',
                'item' => $data['description'] ?? 'Meal Expense',
                'amount' => $data['amount'],
                'category' => $data['category'] ?? 'Meal Expense',
            ]);

            MealExpense::create([
                'transaction_id' => $tx->id,
                'description' => $data['description'] ?? null,
                'category' => $data['category'] ?? null,
                'recorded_by' => auth()->id(),
            ]);

            return redirect()->route('meals.expenses.index')->with('success', 'Expense recorded.');
        });
    }
}
