<?php

namespace App\Http\Controllers;

use App\Models\FxRate;
use App\Models\Institution;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * INSTITUTION SUBSCRIPTION PAYMENTS.
 *
 * INSTITUTION SIDE (an Institution Admin, from their own dashboard)
 *   GET  /settings/subscription          : the plan, what is owed, and a pay form
 *   POST /settings/subscription/payments : SUBMIT a payment (PENDING - no change)
 *
 * PLATFORM SIDE (the Software Super Admin)
 *   GET   /platform/subscription-payments           : the verification queue
 *   PATCH /platform/subscription-payments/{p}/approve : approve -> records the period
 *   PATCH /platform/subscription-payments/{p}/reject  : reject with a reason
 *
 * THE SAFETY RULE, exactly as with member payments: an institution can CLAIM a
 * payment, but only the platform owner can CONFIRM it - and only confirmation
 * extends the service period. That stops an unverified claim silently unlocking a
 * subscription that was never actually paid for.
 */
class SubscriptionPaymentController extends Controller
{
    /* ------------------------------------------------------------------ *
     * INSTITUTION SIDE
     * ------------------------------------------------------------------ */

    public function show(Request $request)
    {
        $institution = Institution::current();

        if (! $institution) {
            return redirect()->route('dashboard')->with('error', 'No workspace is active.');
        }

        $payments = SubscriptionPayment::query()
            ->where('institution_id', $institution->id)
            ->with(['submitter:id,name', 'reviewer:id,name'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (SubscriptionPayment $payment) => [
                'id' => $payment->id,
                'reference' => $payment->reference,
                'amount' => (float) $payment->amount,
                'currency_code' => $payment->currency_code,
                'period_months' => $payment->period_months,
                'method' => $payment->method,
                'method_label' => $payment->methodLabel(),
                'status' => $payment->status,
                'status_tone' => $payment->statusTone(),
                'paid_on' => $payment->paid_on?->format('j M Y'),
                'payer_reference' => $payment->payer_reference,
                'note' => $payment->note,
                'review_note' => $payment->review_note,
                'reviewer' => $payment->reviewer?->name,
                'covers_from' => $payment->covers_from?->format('j M Y'),
                'covers_to' => $payment->covers_to?->format('j M Y'),
                'created_at' => $payment->created_at?->format('j M Y H:i'),
            ]);

        return Inertia::render('Settings/Subscription', [
            'institution' => [
                'id' => $institution->id,
                'name' => $institution->name,
                'subscription_status' => $institution->subscription_status,
                'subscription_label' => $institution->subscriptionLabel(),
                'subscription_tone' => $institution->subscriptionTone(),
                'subscription_plan' => $institution->subscription_plan,
                'subscription_amount' => (float) $institution->subscription_amount,
                'currency_code' => $institution->currency_code,
                'onboarding_mode' => $institution->onboarding_mode,
                'trial_ends_at' => $institution->trial_ends_at?->format('j M Y'),
                'trial_days_left' => $institution->trialDaysLeft(),
                'is_on_trial' => $institution->isOnTrial(),
            ],
            'payments' => $payments,
            'methods' => collect(SubscriptionPayment::METHODS)
                ->map(fn (string $label, string $key) => ['value' => $key, 'label' => $label])
                ->values(),
            'pendingTotal' => (float) SubscriptionPayment::query()
                ->where('institution_id', $institution->id)
                ->where('status', 'pending')
                ->sum('amount'),
            // Only an Institution Admin (or an SSA switched in) may submit.
            'canSubmit' => $this->canSubmit($request->user()),
        ]);
    }

    /**
     * SUBMIT a subscription payment.
     *
     * Creates a PENDING intent only - it deliberately does NOT change the
     * institution's subscription status. That is what stops an unverified claim
     * extending access.
     */
    public function store(Request $request)
    {
        $institution = Institution::current();

        if (! $institution) {
            return back()->with('error', 'No workspace is active.');
        }

        if (! $this->canSubmit($request->user())) {
            return back()->with('error', 'Only an Institution Admin can submit a subscription payment.');
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:10000000'],
            'currency_code' => ['required', 'string', 'max:10'],
            'period_months' => ['required', 'integer', 'min:1', 'max:36'],
            'method' => ['required', Rule::in(array_keys(SubscriptionPayment::METHODS))],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'payer_reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        // Double-tap guard: the same amount submitted twice within a minute is
        // almost certainly a double submission, not two payments.
        $duplicate = SubscriptionPayment::query()
            ->where('institution_id', $institution->id)
            ->where('amount', $data['amount'])
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subMinute())
            ->exists();

        if ($duplicate) {
            return back()->with('error', 'That payment was just submitted and is already awaiting verification.');
        }

        $payment = SubscriptionPayment::create([
            'institution_id' => $institution->id,
            'reference' => SubscriptionPayment::generateReference(),
            'plan_name' => $institution->subscription_plan,
            'amount' => $data['amount'],
            'currency_code' => strtoupper($data['currency_code']),
            'period_months' => $data['period_months'],
            'method' => $data['method'],
            'status' => 'pending',
            'paid_on' => $data['paid_on'],
            'payer_reference' => $data['payer_reference'] ?? null,
            'note' => $data['note'] ?? null,
            'submitted_by' => $request->user()->id,
        ]);

        AuditLogger::log('created', "submitted a subscription payment of {$data['amount']} {$payment->currency_code}", $payment, [
            'reference' => $payment->reference,
            'period_months' => $payment->period_months,
        ], ['subject_label' => $institution->name, 'institution_id' => $institution->id]);

        return back()->with(
            'success',
            "Payment submitted. The platform team will verify it and your subscription will be extended. Reference {$payment->reference}."
        );
    }

    /* ------------------------------------------------------------------ *
     * PLATFORM SIDE (SSA)
     * ------------------------------------------------------------------ */

    public function queue(Request $request)
    {
        $this->authorisePlatform($request);

        $status = (string) $request->query('status', 'pending');

        $payments = SubscriptionPayment::withoutTenantScope()
            ->with(['institution:id,name,currency_code', 'submitter:id,name', 'reviewer:id,name'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (SubscriptionPayment $payment) => [
                'id' => $payment->id,
                'reference' => $payment->reference,
                'institution' => $payment->institution?->name,
                'institution_id' => $payment->institution_id,
                'plan_name' => $payment->plan_name,
                'amount' => (float) $payment->amount,
                'currency_code' => $payment->currency_code,
                // The reporting-currency equivalent, so the SSA can see the total
                // value of the queue across institutions billing differently.
                'amount_in_reporting' => FxRate::convert(
                    (float) $payment->amount,
                    (string) $payment->currency_code,
                    FxRateController::REPORTING_CURRENCY,
                ),
                'period_months' => $payment->period_months,
                'method' => $payment->method,
                'method_label' => $payment->methodLabel(),
                'status' => $payment->status,
                'status_tone' => $payment->statusTone(),
                'paid_on' => $payment->paid_on?->format('j M Y'),
                'payer_reference' => $payment->payer_reference,
                'note' => $payment->note,
                'review_note' => $payment->review_note,
                'submitter' => $payment->submitter?->name,
                'reviewer' => $payment->reviewer?->name,
                'created_at' => $payment->created_at?->format('j M Y H:i'),
            ]);

        return Inertia::render('SSA/SubscriptionPayments', [
            'payments' => $payments,
            'filters' => ['status' => $status],
            'reportingCurrency' => FxRateController::REPORTING_CURRENCY,
            'summary' => [
                'pending' => SubscriptionPayment::withoutTenantScope()->where('status', 'pending')->count(),
                'pending_value' => (float) SubscriptionPayment::withoutTenantScope()
                    ->where('status', 'pending')->sum('amount'),
                'approved_this_month' => (float) SubscriptionPayment::withoutTenantScope()
                    ->where('status', 'approved')
                    ->where('reviewed_at', '>=', now()->startOfMonth())
                    ->sum('amount'),
            ],
        ]);
    }

    /**
     * APPROVE: the moment the subscription is actually extended.
     *
     * The service period is computed from the payment's own `period_months` and
     * starts at whichever is later - today, or the institution's current paid-up
     * date - so approving early never shortens what they already have.
     */
    public function approve(Request $request, SubscriptionPayment $subscriptionPayment)
    {
        $this->authorisePlatform($request);

        // The SSA acts GLOBALLY, so the tenant scope must be lifted for this row.
        // Route-model binding resolves through the model's global scope, which for
        // an SSA on the platform view (no active tenant) would otherwise hide a
        // payment belonging to a specific institution.
        $subscriptionPayment = SubscriptionPayment::withoutTenantScope()->findOrFail($subscriptionPayment->id);

        if (! $subscriptionPayment->isPending()) {
            return back()->with('error', 'That payment has already been reviewed.');
        }

        $data = $request->validate([
            'review_note' => ['nullable', 'string', 'max:500'],
        ]);

        // Institution IS the tenant, so it does not carry the tenant scope - a
        // plain find is correct here (and needs no scope lifting).
        $institution = Institution::find($subscriptionPayment->institution_id);

        if (! $institution) {
            return back()->with('error', 'That institution no longer exists.');
        }

        $months = max((int) $subscriptionPayment->period_months, 1);

        // Start the new period where the old one ends (if it is still in the
        // future), otherwise from today - so an early payment is never wasted.
        $existingEnd = $institution->subscription_ends_at;
        $coversFrom = $existingEnd && $existingEnd->isFuture()
            ? $existingEnd->copy()
            : now()->startOfDay();

        $coversTo = $coversFrom->copy()->addMonths($months);

        $subscriptionPayment->forceFill([
            'status' => 'approved',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $data['review_note'] ?? null,
            'covers_from' => $coversFrom->toDateString(),
            'covers_to' => $coversTo->toDateString(),
        ])->save();

        // Extend the institution: a paid, active subscription.
        $institution->forceFill([
            'subscription_status' => 'paid',
            'onboarding_mode' => 'subscription',
            'subscription_ends_at' => $coversTo,
        ])->save();

        AuditLogger::log('updated', "approved the subscription payment {$subscriptionPayment->reference}", $subscriptionPayment, [
            'institution' => $institution->name,
            'amount' => (float) $subscriptionPayment->amount,
            'covers_to' => $coversTo->toDateString(),
        ], ['subject_label' => $institution->name, 'institution_id' => $institution->id]);

        return back()->with(
            'success',
            "Payment {$subscriptionPayment->reference} approved. {$institution->name} is paid up to {$coversTo->format('j M Y')}."
        );
    }

    /** REJECT a payment, with a reason the institution will see. */
    public function reject(Request $request, SubscriptionPayment $subscriptionPayment)
    {
        $this->authorisePlatform($request);

        // See approve(): the SSA is a GLOBAL actor, so the tenant scope is lifted
        // for this specific row before it is inspected or written.
        $subscriptionPayment = SubscriptionPayment::withoutTenantScope()->findOrFail($subscriptionPayment->id);

        if (! $subscriptionPayment->isPending()) {
            return back()->with('error', 'That payment has already been reviewed.');
        }

        $data = $request->validate([
            'review_note' => ['required', 'string', 'max:500'],
        ]);

        $subscriptionPayment->forceFill([
            'status' => 'rejected',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $data['review_note'],
        ])->save();

        AuditLogger::log('updated', "rejected the subscription payment {$subscriptionPayment->reference}", $subscriptionPayment, [
            'reason' => $data['review_note'],
        ], ['subject_label' => $subscriptionPayment->reference, 'institution_id' => $subscriptionPayment->institution_id]);

        return back()->with('success', "Payment {$subscriptionPayment->reference} rejected.");
    }

    /* ------------------------------------------------------------------ *
     * Authorisation
     * ------------------------------------------------------------------ */

    /** Only an Institution Admin (or an SSA switched into the workspace) submits. */
    protected function canSubmit(?User $user): bool
    {
        return $user !== null && ($user->isInstitutionAdmin() || $user->isSuperAdmin());
    }

    /** Only the global Software Super Admin verifies platform revenue. */
    protected function authorisePlatform(Request $request): void
    {
        abort_unless(
            $request->user()?->isSuperAdmin(),
            403,
            'Only the Software Super Admin can verify subscription payments.'
        );
    }
}
