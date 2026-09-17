<?php

namespace App\Http\Controllers\Meals;

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\Institution;
use App\Models\Student;
use App\Models\Subsidy;
use App\Models\Transaction;
use App\Support\AuditLogger;
use App\Support\Money;
use App\Support\Notifier;
use App\Support\ReportExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class DepositController extends Controller
{

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $studentId = (string) $request->query('student', '');
        $from = $request->query('from');
        $to = $request->query('to');

        $kind = (string) $request->query('kind', '');

        // A Meal Manager only sees deposits for their assigned members.
        $scopedIds = $request->user()->scopedStudentIds();

        $deposits = Deposit::query()
            ->with(['student:id,name,roll', 'recorder:id,name', 'reverser:id,name', 'subsidy:id,source,apply_mode'])
            ->when($scopedIds !== null, fn ($q) => $q->whereIn('student_id', $scopedIds))
            ->when($studentId !== '', fn ($q) => $q->where('student_id', $studentId))
            ->when($kind !== '', fn ($q) => $q->where('kind', $kind))
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

        // Totals split by kind so subsidy money is never silently counted as a
        // personal contribution.
        $totalsBase = fn () => Deposit::query()
            ->when($scopedIds !== null, fn ($q) => $q->whereIn('student_id', $scopedIds))
            ->when($studentId !== '', fn ($q) => $q->where('student_id', $studentId))
            ->when($kind !== '', fn ($q) => $q->where('kind', $kind))
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to));

        $filteredTotal = (float) $totalsBase()->sum('amount');
        $personalTotal = (float) $totalsBase()->where('kind', 'personal')->sum('amount');
        $subsidyAllocated = (float) $totalsBase()->where('kind', 'subsidy')->sum('amount');

        // Subsidy grants recorded at source (not yet distributed per member).
        $subsidyGrants = (float) Subsidy::query()
            ->active()
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->sum('amount');

        return Inertia::render('Meals/Deposits/Index', [
            'deposits' => $deposits,
            'students' => Student::query()
                ->when($scopedIds !== null, fn ($q) => $q->whereIn('id', $scopedIds))
                ->orderBy('name')
                ->get(['id', 'name', 'roll']),
            'kinds' => collect(Deposit::KINDS)
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'filteredTotal' => $filteredTotal,
            'personalTotal' => $personalTotal,
            'subsidyAllocated' => $subsidyAllocated,
            'subsidyGrants' => $subsidyGrants,
            'canManageSubsidies' => $request->user()->can('subsidies.manage'),
            'filters' => [
                'search' => $search,
                'student' => $studentId,
                'kind' => $kind,
                'from' => $from,
                'to' => $to,
            ],
        ]);
    }

    /**
     * Excel / PDF export of the (filtered) deposit ledger.
     */
    public function export(Request $request)
    {
        $from = $request->query('from');
        $to = $request->query('to');
        $kind = (string) $request->query('kind', '');
        $studentId = (string) $request->query('student', '');

        $rows = Deposit::query()
            ->with(['student:id,name,roll', 'recorder:id,name'])
            ->when($studentId !== '', fn ($q) => $q->where('student_id', $studentId))
            ->when($kind !== '', fn ($q) => $q->where('kind', $kind))
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Deposit $d) => [
                'date' => $d->created_at?->format('Y-m-d'),
                'student' => $d->student?->name,
                'roll' => $d->student?->roll,
                'kind' => Deposit::KINDS[$d->kind] ?? $d->kind,
                'amount' => (float) $d->amount,
                'payment_method' => $d->payment_method,
                'recorded_by' => $d->recorder?->name,
                'notes' => $d->notes,
            ]);

        $format = $request->query('format', 'excel');

        $exporter = new ReportExporter(
            filename: 'deposits-'.now()->format('Ymd'),
            title: 'Deposit & Subsidy Ledger',
            columns: [
                'date' => 'Date',
                'student' => 'Member',
                'roll' => 'Roll ID',
                'kind' => 'Type',
                'amount' => 'Amount',
                'payment_method' => 'Method',
                'recorded_by' => 'Recorded By',
                'notes' => 'Notes',
            ],
            rows: $rows,
            meta: [
                'Institution' => Institution::current()?->name ?? '-',
                'Rows' => $rows->count(),
                'Total' => Money::format((float) $rows->sum('amount')),
            ],
            formatter: fn ($value, $key) => $key === 'amount' ? Money::format((float) $value) : $value,
        );

        ActivityLogController::recordExport($request, 'Deposit & Subsidy Ledger', [
            'format' => $format,
            'rows' => $rows->count(),
        ]);

        return $format === 'pdf' ? $exporter->pdf() : $exporter->excel();
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
            // Personal deposits by default; admins may post an adjustment.
            'kind' => ['nullable', Rule::in(array_keys(Deposit::KINDS))],
        ]);

        $kind = $data['kind'] ?? 'personal';

        // A Meal Manager may only deposit to a member assigned to them.
        $scopedIds = $request->user()->scopedStudentIds();
        if ($scopedIds !== null && ! in_array((int) $data['student_id'], $scopedIds, true)) {
            return back()->with('error', 'That member is not assigned to you.');
        }

        return DB::transaction(function () use ($data, $kind) {
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

            Deposit::create([
                'student_id' => $student->id,
                'amount' => $data['amount'],
                'kind' => $kind,
                'payment_method' => $data['payment_method'] ?? null,
                'recorded_by' => auth()->id(),
                'transaction_id' => $tx->id,
                'notes' => $data['notes'] ?? null,
            ]);

            // Audit trail: the deposit is a financial event, so record it
            // explicitly with the member it was credited to.
            AuditLogger::log('created', "recorded a deposit for {$student->name}", $student, [
                'amount' => (float) $data['amount'],
                'kind' => $kind,
                'payment_method' => $data['payment_method'] ?? null,
            ], ['subject_label' => $student->name, 'institution_id' => $student->institution_id]);

            // Let the member know their balance changed.
            Notifier::depositRecorded($student, (float) $data['amount'], 'deposit', auth()->user());

            return redirect()->route('meals.deposits.index')->with('success', 'Deposit recorded.');
        });
    }

    /**
     * Edit an existing deposit. The amount on the linked ledger transaction is
     * kept in sync so the member's balance (which reads from deposits) and the
     * cash-in transaction never disagree.
     */
    public function update(Request $request, Deposit $deposit)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'kind' => ['nullable', Rule::in(array_keys(Deposit::KINDS))],
        ]);

        return DB::transaction(function () use ($data, $deposit) {
            $before = [
                'amount' => (float) $deposit->amount,
                'kind' => $deposit->kind,
                'payment_method' => $deposit->payment_method,
                'notes' => $deposit->notes,
            ];

            $deposit->update([
                'amount' => $data['amount'],
                'payment_method' => $data['payment_method'] ?? null,
                'notes' => $data['notes'] ?? null,
                'kind' => $data['kind'] ?? $deposit->kind,
            ]);

            // Mirror the change onto the ledger transaction that backs it.
            if ($deposit->transaction) {
                $deposit->transaction->update([
                    'amount' => $data['amount'],
                    'payment_method' => $data['payment_method'] ?? null,
                    'reason' => $data['notes'] ?? null,
                ]);
            }

            $student = $deposit->student;

            // Only notify when the amount actually changed, so an edit that just
            // fixes a note does not spam the member.
            if (abs($before['amount'] - (float) $data['amount']) > 0.001) {
                Notifier::depositRecorded($student, (float) $data['amount'], 'adjusted', auth()->user());
            }

            AuditLogger::log('updated', 'edited a deposit for '.($student?->name ?? 'a member'), $deposit, [
                'before' => $before,
                'after' => [
                    'amount' => (float) $data['amount'],
                    'kind' => $data['kind'] ?? $deposit->kind,
                    'payment_method' => $data['payment_method'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ],
            ], ['subject_label' => $student?->name, 'institution_id' => $student?->institution_id]);

            return back()->with('success', 'Deposit updated.');
        });
    }

    /**
     * Reverse a deposit. The row is kept for history but marked reversed, and a
     * matching cash-out is posted so the ledger and the member's balance both
     * fall by the deposit amount.
     */
    public function reverse(Request $request, Deposit $deposit)
    {
        if ($deposit->isReversed()) {
            return back()->with('error', 'This deposit is already reversed.');
        }

        return DB::transaction(function () use ($request, $deposit) {
            $student = $deposit->student;

            // Post the counter entry so the cash-in is neutralised in the books.
            $reversal = Transaction::create([
                'user_id' => $request->user()->id,
                'student_id' => $student?->id,
                'type' => 'out',
                'item' => 'Deposit reversal - '.($student?->name ?? 'member'),
                'amount' => $deposit->amount,
                'category' => 'Deposit Reversal',
                'reason' => 'Reversal of deposit #'.$deposit->id,
                'source' => 'deposit',
            ]);

            $deposit->update([
                'reversed_at' => now(),
                'reversed_by' => $request->user()->id,
                'reversal_transaction_id' => $reversal->id,
            ]);

            AuditLogger::log('reversed', 'reversed a deposit for '.($student?->name ?? 'a member'), $deposit, [
                'amount' => (float) $deposit->amount,
                'reversal_transaction_id' => $reversal->id,
            ], ['subject_label' => $student?->name, 'institution_id' => $student?->institution_id]);

            return back()->with('success', 'Deposit reversed. A matching cash-out was posted.');
        });
    }
}
