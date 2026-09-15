<?php

namespace App\Http\Controllers\Meals;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\Student;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DepositController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:meals.deposit');
    }

    public function index()
    {
        $deposits = Deposit::with('student')->orderBy('created_at', 'desc')->paginate(20);
        return Inertia::render('Meals/Deposits/Index', ['deposits' => $deposits]);
    }

    public function create()
    {
        $students = Student::orderBy('name')->get();
        return Inertia::render('Meals/Deposits/Create', ['students' => $students]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($data) {
            $student = Student::findOrFail($data['student_id']);

            $tx = Transaction::create([
                'type' => 'in',
                'item' => 'Meal Deposit for ' . $student->name,
                'amount' => $data['amount'],
                'category' => 'Meal Deposit',
                'payment_method' => $data['payment_method'] ?? null,
                'by_whom' => $student->name,
            ]);

            $deposit = Deposit::create([
                'student_id' => $student->id,
                'amount' => $data['amount'],
                'payment_method' => $data['payment_method'] ?? null,
                'recorded_by' => auth()->id(),
                'transaction_id' => $tx->id,
                'notes' => $data['notes'] ?? null,
            ]);

            return redirect()->route('meals.deposits.index')->with('success', 'Deposit recorded.');
        });
    }
}
