<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\MemberPaymentController;
use App\Models\MemberPayment;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * MEMBER-INITIATED PAYMENTS API.
 *
 * THE SAFETY RULE, in one sentence: a member can RECORD a payment, but only a
 * manager can ACKNOWLEDGE it — and only acknowledgement moves the balance.
 *
 * A member's POST creates a PENDING intent and nothing else. That is deliberate
 * and is the whole point of the feature: without it, a member could credit their
 * own account to whatever number they liked.
 *
 * Approval creates a real `Deposit` inside a transaction — the same row any
 * manager would record — so the member's balance, the roster, the reports and the
 * ledger all agree afterwards. The write is delegated to the web controller so
 * there is ONE implementation of that money-moving step.
 *
 * ACCESS
 *   - index/store        : the signed-in member's own payments (token-derived).
 *   - queue/approve/reject: staff holding `meals.deposit` (Institution Admin +
 *                          Meal Manager; a Member does not hold it).
 */
class MemberPaymentApiController extends MemberPaymentController
{
    /** GET /api/me/payments — the member's own submitted payments. */
    public function index(Request $request)
    {
        $student = $request->user()->studentRecord();

        if (! $student) {
            return response()->json(['data' => [], 'meta' => ['has_member_record' => false]]);
        }

        $perPage = min((int) $request->query('per_page', 15), 50);

        $payments = MemberPayment::query()
            ->where('student_id', $student->id)
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $payments->through(fn (MemberPayment $p) => $this->present($p));

        return response()->json([
            'data' => $payments->items(),
            'meta' => [
                'has_member_record' => true,
                'balance' => $student->balance(),
                'pending_total' => (float) MemberPayment::query()
                    ->where('student_id', $student->id)
                    ->where('status', 'pending')
                    ->sum('amount'),
                'methods' => $this->methods(),
                'pagination' => [
                    'current_page' => $payments->currentPage(),
                    'last_page' => $payments->lastPage(),
                    'per_page' => $payments->perPage(),
                    'total' => $payments->total(),
                ],
            ],
        ]);
    }

    /** POST /api/me/payments — submit a payment intent (PENDING). */
    public function store(Request $request)
    {
        $student = $request->user()->studentRecord();

        if (! $student) {
            return response()->json([
                'message' => 'Your account is not linked to a member record yet.',
            ], 409);
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:1000000'],
            'method' => ['required', Rule::in(array_keys(MemberPayment::METHODS))],
            'payer_reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        // Server-side double-tap guard: the same member submitting the same amount
        // twice within a minute is almost always a double-tap, not intent. The
        // mobile client is prone to this (a flaky connection invites a re-tap),
        // which is precisely why the guard lives on the server.
        $duplicate = MemberPayment::query()
            ->where('student_id', $student->id)
            ->where('amount', $data['amount'])
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subMinute())
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'That payment was just submitted and is already awaiting verification.',
            ], 409);
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

        return response()->json([
            'data' => $this->present($payment),
            'message' => "Payment submitted. Your manager will verify it, and it will then appear in your balance. Reference {$payment->reference}.",
        ], 201);
    }

    /**
     * GET /api/member-payments — the staff verification queue.
     *
     * Permission-gated by `meals.deposit` on the route.
     */
    public function queue(Request $request)
    {
        $status = (string) $request->query('status', 'pending');
        $perPage = min((int) $request->query('per_page', 20), 100);

        $payments = MemberPayment::query()
            ->with(['student:id,name,roll', 'reviewer:id,name'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $payments->through(fn (MemberPayment $p) => $this->present($p, true));

        return response()->json([
            'data' => $payments->items(),
            'meta' => [
                'summary' => [
                    'pending' => MemberPayment::query()->where('status', 'pending')->count(),
                    'pending_total' => (float) MemberPayment::query()->where('status', 'pending')->sum('amount'),
                    'approved_this_month' => (float) MemberPayment::query()
                        ->where('status', 'approved')
                        ->where('reviewed_at', '>=', now()->startOfMonth())
                        ->sum('amount'),
                ],
                'pagination' => [
                    'current_page' => $payments->currentPage(),
                    'last_page' => $payments->lastPage(),
                    'per_page' => $payments->perPage(),
                    'total' => $payments->total(),
                ],
            ],
        ]);
    }

    /**
     * PATCH /api/member-payments/{memberPayment}/approve
     *
     * Delegates to the web controller so the Deposit-creating transaction exists
     * in exactly one place; this method only translates the redirect into JSON.
     */
    public function approve(Request $request, MemberPayment $memberPayment)
    {
        if (! $memberPayment->isPending()) {
            return response()->json(['message' => 'That payment has already been reviewed.'], 409);
        }

        parent::approve($request, $memberPayment);

        if (session()->has('error')) {
            return response()->json(['message' => session()->get('error')], 409);
        }

        return response()->json([
            'data' => $this->present($memberPayment->fresh(), true),
            'message' => "Payment {$memberPayment->reference} approved. The member's balance has been updated.",
        ]);
    }

    /** PATCH /api/member-payments/{memberPayment}/reject — no money moves. */
    public function reject(Request $request, MemberPayment $memberPayment)
    {
        if (! $memberPayment->isPending()) {
            return response()->json(['message' => 'That payment has already been reviewed.'], 409);
        }

        parent::reject($request, $memberPayment);

        if (session()->has('error')) {
            return response()->json(['message' => session()->get('error')], 422);
        }

        return response()->json([
            'data' => $this->present($memberPayment->fresh(), true),
            'message' => "Payment {$memberPayment->reference} rejected.",
        ]);
    }

    /* ------------------------------------------------------------------ */

    /** Serialise a payment. `$staff` adds the reviewer + member identity. */
    protected function present(MemberPayment $payment, bool $staff = false): array
    {
        $payload = [
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
            'created_at' => $payment->created_at?->toIso8601String(),
            'reviewed_at' => $payment->reviewed_at?->toIso8601String(),
        ];

        if ($staff) {
            $payload['student'] = $payment->student ? [
                'id' => $payment->student->id,
                'name' => $payment->student->name,
                'roll' => $payment->student->roll,
            ] : null;
            $payload['reviewer'] = $payment->reviewer?->name;
        }

        return $payload;
    }

    /** `[{ value, label }]` for the method picker. */
    protected function methods(): array
    {
        return collect(MemberPayment::METHODS)
            ->map(fn (array $meta, string $key) => ['value' => $key, 'label' => $meta['label']])
            ->values()
            ->all();
    }
}
