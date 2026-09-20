<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\Student;
use App\Models\Transaction;
use App\Support\FinanceCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Deposits = Cash In. Each deposit also writes a matching ledger transaction.
 */
class DepositApiController extends Controller
{
    public function index(Request $request)
    {
        $month = FinanceCalculator::resolveMonth($request->query('month'));
        [$start, $end] = FinanceCalculator::monthBounds($month);

        $query = Deposit::query()
            ->with(['student:id,name,roll', 'recorder:id,name'])
            ->when($request->filled('member'), fn ($q) => $q->where('student_id', $request->query('member')))
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->query('kind')))
            ->when($start, fn ($q) => $q->whereDate('created_at', '>=', $start->toDateString()))
            ->when($end, fn ($q) => $q->whereDate('created_at', '<=', $end->toDateString()))
            ->orderByDesc('created_at');

        $perPage = min((int) $request->query('per_page', 25), 100);
        $deposits = $query->paginate($perPage)->withQueryString();

        $deposits->through(fn (Deposit $d) => [
            'id' => $d->id,
            'member' => $d->student?->name,
            'member_id' => $d->student_id,
            'roll' => $d->student?->roll,
            'amount' => (float) $d->amount,
            'kind' => $d->kind,
            'payment_method' => $d->payment_method,
            'recorded_by' => $d->recorder?->name,
            'notes' => $d->notes,
            'date' => $d->created_at->toIso8601String(),
        ]);

        $totals = Deposit::query()
            ->when($start, fn ($q) => $q->whereDate('created_at', '>=', $start->toDateString()))
            ->when($end, fn ($q) => $q->whereDate('created_at', '<=', $end->toDateString()))
            ->selectRaw("SUM(CASE WHEN kind = 'personal' THEN amount ELSE 0 END) as personal")
            ->selectRaw("SUM(CASE WHEN kind = 'subsidy' THEN amount ELSE 0 END) as subsidy")
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->first();

        return response()->json([
            'data' => $deposits->items(),
            'meta' => [
                'month' => $month,
                'totals' => [
                    'all' => (float) $totals->total,
                    'personal' => (float) $totals->personal,
                    'subsidy' => (float) $totals->subsidy,
                ],
                'kinds' => Deposit::KINDS,
                'members' => Student::active()->orderBy('name')->get(['id', 'name', 'roll']),
                'pagination' => [
                    'current_page' => $deposits->currentPage(),
                    'last_page' => $deposits->lastPage(),
                    'total' => $deposits->total(),
                ],
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['nullable', 'string', 'max:60'],
            'kind' => ['nullable', Rule::in(array_keys(Deposit::KINDS))],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $deposit = DB::transaction(function () use ($data, $request) {
            $student = Student::findOrFail($data['student_id']);

            $tx = Transaction::create([
                'user_id' => $request->user()->id,
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

            return Deposit::create([
                'student_id' => $student->id,
                'amount' => $data['amount'],
                'kind' => $data['kind'] ?? 'personal',
                'payment_method' => $data['payment_method'] ?? null,
                'recorded_by' => $request->user()->id,
                'transaction_id' => $tx->id,
                'notes' => $data['notes'] ?? null,
            ]);
        });

        return response()->json([
            'data' => ['id' => $deposit->id, 'amount' => (float) $deposit->amount],
            'message' => 'Deposit recorded.',
        ], 201);
    }

    /**
     * Export the deposit ledger as JSON. The mobile client renders it; a
     * server-side file download is also available via the web export route.
     */
    public function export(Request $request)
    {
        $month = FinanceCalculator::resolveMonth($request->query('month'));
        [$start, $end] = FinanceCalculator::monthBounds($month);

        $rows = Deposit::query()
            ->with('student:id,name,roll')
            ->when($start, fn ($q) => $q->whereDate('created_at', '>=', $start->toDateString()))
            ->when($end, fn ($q) => $q->whereDate('created_at', '<=', $end->toDateString()))
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Deposit $d) => [
                'date' => $d->created_at->toDateString(),
                'member' => $d->student?->name,
                'roll' => $d->student?->roll,
                'kind' => $d->kind,
                'amount' => (float) $d->amount,
                'payment_method' => $d->payment_method,
                'notes' => $d->notes,
            ]);

        return response()->json([
            'data' => $rows,
            'meta' => ['month' => $month, 'total' => (float) $rows->sum('amount'), 'count' => $rows->count()],
        ]);
    }
}
