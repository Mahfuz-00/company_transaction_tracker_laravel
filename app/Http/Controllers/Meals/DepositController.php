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

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $studentId = (string) $request->query('student', '');
        $from = $request->query('from');
        $to = $request->query('to');

        $deposits = Deposit::query()
            ->with(['student:id,name,roll', 'recorder:id,name'])
            ->when($studentId !== '', fn ($q) => $q->where('student_id', $studentId))
            ->when($search !== '', function ($q) use ($search) {
                $term = '%'.$search.'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('notes', 'like', $term)
                        ->orWhere('payment_method', 'like', $term)
                        ->orWhereHas('student', fn ($s) => $s->where('name', 'like', $term));
                });
            })
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        // Total for the active filter, not just the current page.
        $filteredTotal = Deposit::query()
            ->when($studentId !== '', fn ($q) => $q->where('student_id', $studentId))
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->sum('amount');

        return Inertia::render('Meals/Deposits/Index', [
            'deposits' => $deposits,
            'students' => Student::orderBy('name')->get(['id', 'name', 'roll']),
            'filteredTotal' => (float) $filteredTotal,
            'filters' => [
                'search' => $search,
                'student' => $studentId,
                'from' => $from,
                'to' => $to,
            ],
        ]);
    }

    public function create()
    {
        return redirect()->route('meals.deposits.index');
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

            // transactions.user_id is NOT NULL - omitting it fails the insert.
            $tx = Transaction::create([
                'user_id' => auth()->id(),
                // Links the cash-in explicitly to the student who paid it.
                'student_id' => $student->id,
                'type' => 'in',
                'item' => 'Meal Deposit for ' . $student->name,
                'amount' => $data['amount'],
                'category' => 'Meal Deposit',
                'payment_method' => $data['payment_method'] ?? null,
                'by_whom' => $student->name,
                'reason' => $data['notes'] ?? null,
                'source' => 'deposit',
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
