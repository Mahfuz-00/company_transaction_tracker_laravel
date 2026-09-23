<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreDepositRequest;
use App\Http\Resources\DepositResource;
use App\Models\Deposit;
use App\Models\Student;
use App\Models\Transaction;
use App\Support\FinanceCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Deposits = Cash In. Each deposit also writes a matching ledger transaction.
 *
 * WHY TWO TABLES
 * --------------
 * A deposit is recorded in the module table (`deposits`) AND mirrored into the
 * shared ledger (`transactions`). The module table carries deposit-specific
 * fields (kind, payment method, reversal columns); the ledger row is what the
 * pool balance and the reports sum. They are written together inside one
 * DB::transaction so the two can never disagree.
 *
 * Tenant isolation is automatic: every query below runs through the
 * `BelongsToInstitution` global scope, so a token for one institution can never
 * read or write another's rows.
 */
class DepositApiController extends Controller
{
    /** Paginated deposit ledger for a month, with per-kind totals. */
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

        // Cap the page size so a client cannot request an unbounded dump.
        $perPage = min((int) $request->query('per_page', 25), 100);
        $deposits = $query->paginate($perPage)->withQueryString();

        // Month totals are computed over the WHOLE month, not just this page -
        // otherwise the header figures would change as the user scrolls.
        $totals = Deposit::query()
            ->when($start, fn ($q) => $q->whereDate('created_at', '>=', $start->toDateString()))
            ->when($end, fn ($q) => $q->whereDate('created_at', '<=', $end->toDateString()))
            ->selectRaw("SUM(CASE WHEN kind = 'personal' THEN amount ELSE 0 END) as personal")
            ->selectRaw("SUM(CASE WHEN kind = 'subsidy' THEN amount ELSE 0 END) as subsidy")
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->first();

        return response()->json([
            // The row shape lives in DepositResource (one definition, no drift).
            'data' => DepositResource::collection($deposits->items())->resolve(),
            'meta' => [
                'month' => $month,
                'totals' => [
                    'all' => (float) $totals->total,
                    'personal' => (float) $totals->personal,
                    'subsidy' => (float) $totals->subsidy,
                ],
                'kinds' => Deposit::KINDS,
                // The member picker for the "record deposit" form.
                'members' => Student::active()->orderBy('name')->get(['id', 'name', 'roll']),
                'pagination' => [
                    'current_page' => $deposits->currentPage(),
                    'last_page' => $deposits->lastPage(),
                    'total' => $deposits->total(),
                ],
            ],
        ]);
    }

    /** Record a deposit (and its matching ledger row). */
    public function store(StoreDepositRequest $request)
    {
        /*
         * Validation + tenant scoping happen in StoreDepositRequest BEFORE this
         * method runs: a `student_id` belonging to another institution fails the
         * scoped `exists` rule and returns 422, so the write below can never be
         * pointed at a foreign member.
         */
        $data = $request->validated();

        $deposit = DB::transaction(function () use ($data, $request) {
            // Re-fetch through the tenant-scoped model: a defensive second check
            // that also returns a clean 404 if the row vanished mid-request.
            $student = Student::findOrFail($data['student_id']);

            /*
             * `transactions.payment_method` is NOT NULL with a DB default of
             * 'Cash'. Passing an explicit null (as the original code did) breaks
             * that constraint and 500s the whole request whenever a client omits
             * the field - even though the validation marks it optional. We
             * therefore only include the key when a value was actually sent, so
             * the column default applies otherwise.
             */
            $txPayload = [
                'user_id' => $request->user()->id,
                'student_id' => $student->id,
                'type' => 'in',
                'item' => 'Meal Deposit for ' . $student->name,
                'amount' => $data['amount'],
                'category' => 'Meal Deposit',
                'by_whom' => $student->name,
                'reason' => $data['notes'] ?? null,
                'source' => 'deposit',
            ];

            if (! empty($data['payment_method'])) {
                $txPayload['payment_method'] = $data['payment_method'];
            }

            $tx = Transaction::create($txPayload);

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
     *
     * NOTE: this is intentionally NOT a paginated/Resource response - it is a
     * flat, month-scoped projection for offline rendering, so its row shape
     * differs from the index (no id, date-only) on purpose.
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
