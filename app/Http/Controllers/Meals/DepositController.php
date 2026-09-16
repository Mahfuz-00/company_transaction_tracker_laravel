<?php

namespace App\Http\Controllers\Meals;

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\Institution;
use App\Models\Student;
use App\Models\Subsidy;
use App\Models\Transaction;
use App\Support\Money;
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

        $deposits = Deposit::query()
            ->with(['student:id,name,roll', 'recorder:id,name', 'subsidy:id,source,apply_mode'])
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
            'students' => Student::orderBy('name')->get(['id', 'name', 'roll']),
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

            return redirect()->route('meals.deposits.index')->with('success', 'Deposit recorded.');
        });
    }
}
