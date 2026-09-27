<?php

namespace App\Http\Controllers;

use App\Models\Deposit;
use App\Models\MemberPayment;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * MEMBER-INITIATED MEAL PAYMENTS.
 *
 * MEMBER SIDE (their own dashboard)
 *   GET  /my/payments              : make a payment + see my pending/submitted ones
 *   POST /my/payments              : submit a payment intent (PENDING - no money moves)
 *
 * STAFF SIDE (verification queue)
 *   GET   /meals/member-payments        : pending intents awaiting verification
 *   PATCH /meals/member-payments/{p}/approve : approve -> creates the real Deposit
 *   PATCH /meals/member-payments/{p}/reject  : reject with a reason
 *
 * THE SAFETY RULE, in one sentence: a member can RECORD a payment, but only a
 * manager can ACKNOWLEDGE it - and only acknowledgement moves the balance.
 *
 * Approving does NOT write the balance directly. It creates a proper `Deposit`,
 * which is what every other figure in the platform already reads from, so the
 * member's dashboard, the roster, the reports and the ledger all agree.
 */
class MemberPaymentController extends Controller
{
    /* ------------------------------------------------------------------ *
     * MEMBER SIDE
     * ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $student = $request->user()->studentRecord();

        if (! $student) {
            return Inertia::render('Member/Payments', ['hasMemberRecord' => false]);
        }

        $payments = MemberPayment::query()
            ->where('student_id', $student->id)
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (MemberPayment $payment) => [
                'id' => $payment->id,
                'reference' => $payment->reference,
                'amount' => (float) $payment->amount,
                'method' => $payment->method,
                'method_label' => $payment->methodLabel(),
                'status' => $payment->status,
                'status_tone' => $payment->statusTone(),
                'note' => $payment->note,
                'review_note' => $payment->review_note,
                'created_at' => $payment->created_at?->format('j M Y, H:i'),
                'reviewed_at' => $payment->reviewed_at?->format('j M Y'),
            ]);

        return Inertia::render('Member/Payments', [
            'hasMemberRecord' => true,
            'member' => [
                'name' => $student->name,
                'roll' => $student->roll,
                'balance' => $student->balance(),
            ],
            'payments' => $payments,
            'methods' => collect(MemberPayment::METHODS)
                ->map(fn (array $meta, string $key) => ['value' => $key, 'label' => $meta['label']])
                ->values(),
            'pendingTotal' => (float) MemberPayment::query()
                ->where('student_id', $student->id)
                ->where('status', 'pending')
                ->sum('amount'),
        ]);
    }

    /**
     * A member SUBMITS a payment.
     *
     * This creates a PENDING intent only. It deliberately does not create a
     * Deposit: that is what stops a member crediting their own account.
     */
    public function store(Request $request)
    {
        $student = $request->user()->studentRecord();

        if (! $student) {
            return back()->with('error', 'Your account is not linked to a member record yet.');
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:1000000'],
            'method' => ['required', Rule::in(array_keys(MemberPayment::METHODS))],
            'payer_reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        // A duplicate guard on the SERVER: the same member submitting the same
        // amount twice within a minute is almost always a double-tap, not intent.
        $duplicate = MemberPayment::query()
            ->where('student_id', $student->id)
            ->where('amount', $data['amount'])
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subMinute())
            ->exists();

        if ($duplicate) {
            return back()->with('error', 'That payment was just submitted and is already awaiting verification.');
        }

        $payment = MemberPayment::create([
            'institution_id' => $student->institution_id,
            'student_id' => $student->id,
            'user_id' => $request->user()->id,
            'reference' => MemberPayment::generateReference(),
            'amount' => $data['amount'],
            'method' => $data['method'],
            'status' => 'pending',
            'payer_reference' => $data['payer_reference'] ?? null,
            'note' => $data['note'] ?? null,
        ]);

        AuditLogger::log('created', "submitted a payment of {$data['amount']}", $payment, [
            'reference' => $payment->reference,
            'method' => $payment->method,
        ], ['subject_label' => $student->name, 'institution_id' => $student->institution_id]);

        return back()->with(
            'success',
            "Payment submitted. Your manager will verify it, and it will then appear in your balance. Reference {$payment->reference}."
        );
    }

    /* ------------------------------------------------------------------ *
     * STAFF SIDE
     * ------------------------------------------------------------------ */

    /** The verification queue. */
    public function queue(Request $request)
    {
        $status = (string) $request->query('status', 'pending');

        $payments = MemberPayment::query()
            ->with(['student:id,name,roll', 'reviewer:id,name'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (MemberPayment $payment) => [
                'id' => $payment->id,
                'reference' => $payment->reference,
                'amount' => (float) $payment->amount,
                'method' => $payment->method,
                'method_label' => $payment->methodLabel(),
                'status' => $payment->status,
                'status_tone' => $payment->statusTone(),
                'payer_reference' => $payment->payer_reference,
                'note' => $payment->note,
                'review_note' => $payment->review_note,
                'created_at' => $payment->created_at?->format('j M Y, H:i'),
                'student' => $payment->student ? [
                    'id' => $payment->student->id,
                    'name' => $payment->student->name,
                    'roll' => $payment->student->roll,
                ] : null,
                'reviewer' => $payment->reviewer?->name,
            ]);

        return Inertia::render('Meals/MemberPayments/Index', [
            'payments' => $payments,
            'filters' => ['status' => $status],
            'summary' => [
                'pending' => MemberPayment::query()->where('status', 'pending')->count(),
                'pending_total' => (float) MemberPayment::query()->where('status', 'pending')->sum('amount'),
                'approved_this_month' => (float) MemberPayment::query()
                    ->where('status', 'approved')
                    ->where('reviewed_at', '>=', now()->startOfMonth())
                    ->sum('amount'),
            ],
        ]);
    }

    /**
     * APPROVE a payment: this is the moment money actually moves.
     *
     * Everything happens in one transaction: the Deposit is created, the intent is
     * marked approved and linked to it. A partial failure would be worse than a
     * failed approval, so it is all-or-nothing.
     */
    public function approve(Request $request, MemberPayment $memberPayment)
    {
        if (! $memberPayment->isPending()) {
            return back()->with('error', 'That payment has already been reviewed.');
        }

        $data = $request->validate([
            'review_note' => ['nullable', 'string', 'max:500'],
        ]);

        if (! $memberPayment->student) {
            return back()->with('error', 'That member record no longer exists.');
        }

        DB::transaction(function () use ($memberPayment, $request, $data) {
            // The REAL ledger entry - the same kind any manager would record.
            $deposit = Deposit::create([
                'institution_id' => $memberPayment->institution_id,
                'student_id' => $memberPayment->student_id,
                'amount' => $memberPayment->amount,
                'payment_method' => $memberPayment->method,
                'notes' => 'Member-initiated payment '.$memberPayment->reference
                    .($memberPayment->note ? ' - '.$memberPayment->note : ''),
                'recorded_by' => $request->user()->id,
            ]);

            $memberPayment->forceFill([
                'status' => 'approved',
                'deposit_id' => $deposit->id,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_note' => $data['review_note'] ?? null,
            ])->save();
        });

        AuditLogger::log('updated', "approved the payment {$memberPayment->reference}", $memberPayment, [
            'amount' => (float) $memberPayment->amount,
        ], ['subject_label' => $memberPayment->student->name, 'institution_id' => $memberPayment->institution_id]);

        return back()->with(
            'success',
            "Payment {$memberPayment->reference} approved. The member's balance has been updated."
        );
    }

    /** REJECT a payment, with a reason the member will see. */
    public function reject(Request $request, MemberPayment $memberPayment)
    {
        if (! $memberPayment->isPending()) {
            return back()->with('error', 'That payment has already been reviewed.');
        }

        $data = $request->validate([
            'review_note' => ['required', 'string', 'max:500'],
        ]);

        $memberPayment->forceFill([
            'status' => 'rejected',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $data['review_note'],
        ])->save();

        AuditLogger::log('updated', "rejected the payment {$memberPayment->reference}", $memberPayment, [
            'reason' => $data['review_note'],
        ], ['subject_label' => $memberPayment->student?->name, 'institution_id' => $memberPayment->institution_id]);

        return back()->with('success', "Payment {$memberPayment->reference} rejected.");
    }
}
