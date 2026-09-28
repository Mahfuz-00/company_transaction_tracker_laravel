<?php

namespace Tests\Browser\Subscription\Feature;

use App\Models\SubscriptionPayment;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * INSTITUTION SUBSCRIPTION PAYMENTS.
 *
 * INSTITUTION SIDE
 *   GET  /settings/subscription                (settings.subscription.show)
 *   POST /settings/subscription/payments       (settings.subscription.store)
 *
 * PLATFORM SIDE (SSA)
 *   GET   /platform/subscription-payments                       (queue)
 *   PATCH /platform/subscription-payments/{id}/approve
 *   PATCH /platform/subscription-payments/{id}/reject
 *
 * WHAT THESE TESTS LOCK IN
 *   1. An Institution Admin can SUBMIT a payment from their dashboard.
 *   2. Submitting does NOT change the subscription - it stays PENDING until the
 *      platform owner verifies it. That separation is the whole safety model.
 *   3. APPROVING extends the institution's paid-up period and marks it paid.
 *   4. Only the SSA can verify; an Institution Admin cannot approve their own.
 *   5. A double submission of the same amount is refused.
 */
class SubscriptionPaymentTest extends DuskTestCase
{
    use DuskSupport;

    public function test_an_institution_admin_can_submit_a_subscription_payment(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['name' => 'Paying Workspace']);
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('InstituteAdmin', 'Subscription', 'POST a payment', __LINE__);

        $this->httpAs($admin)
            ->post('/settings/subscription/payments', [
                'amount' => 5000,
                'currency_code' => 'BDT',
                'period_months' => 3,
                'method' => 'bank',
                'paid_on' => now()->toDateString(),
                'payer_reference' => 'BANK-REF-001',
                'note' => 'Q1 payment',
            ])
            ->assertSessionHas('success');

        $payment = SubscriptionPayment::where('institution_id', $institution->id)->first();

        $this->assertNotNull($payment, 'The payment must be recorded.');
        $this->assertSame('pending', $payment->status);
        $this->assertSame('BDT', $payment->currency_code);
        $this->assertSame(3, $payment->period_months);
        $this->assertSame($admin->id, $payment->submitted_by);
        $this->assertStringStartsWith('SUB-', $payment->reference);
    }

    public function test_submitting_a_payment_does_not_change_the_subscription(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['subscription_status' => 'overdue']);
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('InstituteAdmin', 'Subscription', 'a pending payment changes nothing', __LINE__);

        $this->httpAs($admin)->post('/settings/subscription/payments', [
            'amount' => 2500,
            'currency_code' => 'USD',
            'period_months' => 1,
            'method' => 'card',
            'paid_on' => now()->toDateString(),
        ]);

        // THE SAFETY RULE: an unverified claim must NOT unlock the subscription.
        $this->assertSame('overdue', $institution->fresh()->subscription_status);
        $this->assertNull($institution->fresh()->subscription_ends_at);
    }

    public function test_a_duplicate_submission_of_the_same_amount_is_refused(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $payload = [
            'amount' => 4000,
            'currency_code' => 'BDT',
            'period_months' => 1,
            'method' => 'bkash',
            'paid_on' => now()->toDateString(),
        ];

        $this->httpAs($admin)->post('/settings/subscription/payments', $payload);

        $this->step('InstituteAdmin', 'Subscription', 'a double submission is refused', __LINE__);

        // A double-tap within a minute is almost certainly the same payment.
        $this->httpAs($admin)
            ->post('/settings/subscription/payments', $payload)
            ->assertSessionHas('error');

        $this->assertSame(1, SubscriptionPayment::where('institution_id', $institution->id)->count());
    }

    public function test_only_the_super_admin_can_approve_a_payment(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $payment = SubscriptionPayment::create([
            'institution_id' => $institution->id,
            'reference' => 'SUB-TEST-0001',
            'amount' => 1000,
            'currency_code' => 'USD',
            'period_months' => 1,
            'method' => 'bank',
            'status' => 'pending',
            'paid_on' => now()->toDateString(),
            'submitted_by' => $admin->id,
        ]);

        $this->step('InstituteAdmin', 'Subscription', 'self-approval is forbidden', __LINE__);

        // An Institution Admin must never be able to verify their OWN payment.
        $this->httpAs($admin)
            ->patch('/platform/subscription-payments/'.$payment->id.'/approve')
            ->assertForbidden();

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_the_super_admin_can_approve_and_extend_the_subscription(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['subscription_status' => 'pending']);
        $admin = $this->makeInstitutionAdmin($institution);
        $ssa = $this->makeSuperAdmin();

        $payment = SubscriptionPayment::create([
            'institution_id' => $institution->id,
            'reference' => 'SUB-TEST-0002',
            'amount' => 6000,
            'currency_code' => 'BDT',
            'period_months' => 6,
            'method' => 'bank',
            'status' => 'pending',
            'paid_on' => now()->toDateString(),
            'submitted_by' => $admin->id,
        ]);

        $this->step('SoftwareSuperAdmin', 'Subscription', 'approve and extend', __LINE__);

        $this->httpAs($ssa)
            ->patch('/platform/subscription-payments/'.$payment->id.'/approve')
            ->assertSessionHas('success');

        $payment->refresh();
        $institution->refresh();

        $this->assertSame('approved', $payment->status);
        $this->assertSame($ssa->id, $payment->reviewed_by);
        $this->assertNotNull($payment->covers_from);
        $this->assertNotNull($payment->covers_to);

        // The subscription is now PAID and extended by the paid period.
        $this->assertSame('paid', $institution->subscription_status);
        $this->assertNotNull($institution->subscription_ends_at);

        // 6 months of service was bought, so the end date is ~6 months out.
        //
        // `subscription_ends_at` is cast to a DATE, so we compare calendar months
        // between the two dates rather than relying on a float diff (Carbon returns
        // a float here, and a rounded 5.99 would read as 5).
        $endsAt = $institution->subscription_ends_at;

        $this->assertNotNull($endsAt, 'Approving must set the paid-up end date.');

        $monthsPurchased = now()->startOfDay()->diffInMonths($endsAt->copy()->startOfDay());

        $this->assertEqualsWithDelta(
            6,
            $monthsPurchased,
            1,
            'The paid-up period must reflect the months purchased.'
        );
    }

    public function test_the_super_admin_can_reject_a_payment_with_a_reason(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);
        $ssa = $this->makeSuperAdmin();

        $payment = SubscriptionPayment::create([
            'institution_id' => $institution->id,
            'reference' => 'SUB-TEST-0003',
            'amount' => 1000,
            'currency_code' => 'USD',
            'period_months' => 1,
            'method' => 'bank',
            'status' => 'pending',
            'paid_on' => now()->toDateString(),
            'submitted_by' => $admin->id,
        ]);

        $this->step('SoftwareSuperAdmin', 'Subscription', 'reject with a reason', __LINE__);

        $this->httpAs($ssa)
            ->patch('/platform/subscription-payments/'.$payment->id.'/reject', [
                'review_note' => 'No matching bank reference found.',
            ])
            ->assertSessionHas('success');

        $payment->refresh();

        $this->assertSame('rejected', $payment->status);
        $this->assertSame('No matching bank reference found.', $payment->review_note);

        // A rejected payment must NOT extend anything.
        $this->assertNull($institution->fresh()->subscription_ends_at);
    }

    public function test_a_rejected_payment_requires_a_reason(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);
        $ssa = $this->makeSuperAdmin();

        $payment = SubscriptionPayment::create([
            'institution_id' => $institution->id,
            'reference' => 'SUB-TEST-0004',
            'amount' => 1000,
            'currency_code' => 'USD',
            'period_months' => 1,
            'method' => 'bank',
            'status' => 'pending',
            'paid_on' => now()->toDateString(),
            'submitted_by' => $admin->id,
        ]);

        // The institution will SEE the reason, so it is mandatory.
        $this->httpAs($ssa)
            ->patch('/platform/subscription-payments/'.$payment->id.'/reject', [])
            ->assertSessionHasErrors('review_note');

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_a_payment_cannot_be_approved_twice(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);
        $ssa = $this->makeSuperAdmin();

        $payment = SubscriptionPayment::create([
            'institution_id' => $institution->id,
            'reference' => 'SUB-TEST-0005',
            'amount' => 1000,
            'currency_code' => 'USD',
            'period_months' => 1,
            'method' => 'bank',
            'status' => 'pending',
            'paid_on' => now()->toDateString(),
            'submitted_by' => $admin->id,
        ]);

        $this->httpAs($ssa)->patch('/platform/subscription-payments/'.$payment->id.'/approve');

        $this->step('SoftwareSuperAdmin', 'Subscription', 'a second approval is refused', __LINE__);

        // Re-approving must not extend the subscription twice.
        $this->httpAs($ssa)
            ->patch('/platform/subscription-payments/'.$payment->id.'/approve')
            ->assertSessionHas('error');

        $this->assertSame('approved', $payment->fresh()->status);
    }

    public function test_the_institution_billing_page_renders_for_an_admin(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['name' => 'Billing Workspace']);
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('InstituteAdmin', 'Subscription', 'visit /settings/subscription', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/settings/subscription')
                ->waitFor('[data-testid="subscription-submit"]', 20)
                ->assertSee('Billing Workspace')
                ->assertVisible('[data-testid="subscription-status"]');
        });
    }

    public function test_the_ssa_verification_queue_renders(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['name' => 'Queue Workspace']);
        $admin = $this->makeInstitutionAdmin($institution);
        $ssa = $this->makeSuperAdmin();

        SubscriptionPayment::create([
            'institution_id' => $institution->id,
            'reference' => 'SUB-QUEUE-0001',
            'amount' => 3000,
            'currency_code' => 'BDT',
            'period_months' => 2,
            'method' => 'bank',
            'status' => 'pending',
            'paid_on' => now()->toDateString(),
            'submitted_by' => $admin->id,
        ]);

        $this->step('SoftwareSuperAdmin', 'Subscription', 'visit the verification queue', __LINE__);

        $this->browse(function (Browser $browser) use ($ssa) {
            $browser->loginAs($ssa)
                ->visit('/platform/subscription-payments')
                ->waitFor('[data-testid="ssa-payment-row"]', 20)
                ->assertSee('Queue Workspace')
                ->assertSee('SUB-QUEUE-0001')
                ->assertVisible('[data-testid="ssa-payment-approve"]');
        });
    }

    public function test_an_institution_admin_cannot_open_the_ssa_queue(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('InstituteAdmin', 'Subscription', 'the SSA queue is forbidden', __LINE__);

        $this->httpAs($admin)->get('/platform/subscription-payments')->assertForbidden();
    }
}
