<?php

namespace Tests\Browser\MemberPayments\Feature;

use App\Models\Deposit;
use App\Models\MemberPayment;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * MEMBER-INITIATED MEAL PAYMENTS.
 *
 * MEMBER SIDE
 *   GET  /my/payments                    (member.payments)
 *   POST /my/payments                    (member.payments.store)
 *
 * STAFF SIDE
 *   GET   /meals/member-payments              (queue)
 *   PATCH /meals/member-payments/{id}/approve
 *   PATCH /meals/member-payments/{id}/reject
 *
 * THE SAFETY MODEL, which is what these tests exist to protect:
 *   A member can CLAIM a payment. Only a manager can CONFIRM it. Until then the
 *   balance is untouched - and approval creates a real Deposit, so every other
 *   figure in the platform agrees.
 */
class MemberPaymentTest extends DuskTestCase
{
    use DuskSupport;

    public function test_a_member_can_submit_a_payment_claim(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution, ['email' => 'payer@example.test']);

        $this->step('Member', 'Payments', 'POST /my/payments', __LINE__);

        $this->httpAs($member)
            ->post('/my/payments', [
                'amount' => 2000,
                'method' => 'bkash',
                'payer_reference' => 'TRX123456',
                'note' => 'Monthly top-up',
            ])
            ->assertSessionHas('success');

        $payment = MemberPayment::where('user_id', $member->id)->first();

        $this->assertNotNull($payment, 'The payment claim must be recorded.');
        $this->assertSame('pending', $payment->status);
        $this->assertSame('bkash', $payment->method);
        $this->assertStringStartsWith('MP-', $payment->reference);
    }

    public function test_submitting_a_payment_does_not_credit_the_balance(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution, ['email' => 'nobalance@example.test']);

        $student = $member->studentRecord();

        $this->step('Member', 'Payments', 'a pending claim moves no money', __LINE__);

        $this->httpAs($member)->post('/my/payments', [
            'amount' => 5000,
            'method' => 'cash',
        ]);

        // THE SAFETY RULE: no deposit exists yet, so the balance is unchanged.
        $this->assertSame(0, Deposit::where('student_id', $student->id)->count());
    }

    public function test_a_duplicate_submission_is_refused(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution, ['email' => 'double@example.test']);

        $payload = ['amount' => 1500, 'method' => 'cash'];

        $this->httpAs($member)->post('/my/payments', $payload);

        $this->step('Member', 'Payments', 'a double-tap is refused', __LINE__);

        $this->httpAs($member)
            ->post('/my/payments', $payload)
            ->assertSessionHas('error');

        $this->assertSame(1, MemberPayment::where('user_id', $member->id)->count());
    }

    public function test_a_manager_approving_a_payment_creates_a_real_deposit(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution, ['email' => 'approve-me@example.test']);
        $admin = $this->makeInstitutionAdmin($institution);

        $student = $member->studentRecord();

        $payment = MemberPayment::create([
            'institution_id' => $institution->id,
            'student_id' => $student->id,
            'user_id' => $member->id,
            'reference' => 'MP-TEST-0001',
            'amount' => 3000,
            'method' => 'bkash',
            'status' => 'pending',
        ]);

        $this->step('InstituteAdmin', 'Payments', 'approve creates a deposit', __LINE__);

        $this->httpAs($admin)
            ->patch('/meals/member-payments/'.$payment->id.'/approve')
            ->assertSessionHas('success');

        $payment->refresh();

        $this->assertSame('approved', $payment->status);
        $this->assertNotNull($payment->deposit_id, 'Approval must link to the deposit it created.');

        // The REAL ledger entry now exists - this is what makes every other
        // figure in the platform agree.
        $deposit = Deposit::where('student_id', $student->id)->first();
        $this->assertNotNull($deposit);
        $this->assertSame('3000.00', (string) $deposit->amount);
        $this->assertSame($admin->id, $deposit->recorded_by);
    }

    public function test_a_manager_can_reject_a_payment_with_a_reason(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution, ['email' => 'reject-me@example.test']);
        $admin = $this->makeInstitutionAdmin($institution);

        $student = $member->studentRecord();

        $payment = MemberPayment::create([
            'institution_id' => $institution->id,
            'student_id' => $student->id,
            'user_id' => $member->id,
            'reference' => 'MP-TEST-0002',
            'amount' => 1000,
            'method' => 'cash',
            'status' => 'pending',
        ]);

        $this->step('InstituteAdmin', 'Payments', 'reject with a reason', __LINE__);

        $this->httpAs($admin)
            ->patch('/meals/member-payments/'.$payment->id.'/reject', [
                'review_note' => 'No matching transaction found.',
            ])
            ->assertSessionHas('success');

        $payment->refresh();

        $this->assertSame('rejected', $payment->status);
        $this->assertSame('No matching transaction found.', $payment->review_note);

        // A rejected claim creates nothing.
        $this->assertSame(0, Deposit::where('student_id', $student->id)->count());
    }

    public function test_a_rejection_requires_a_reason(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution, ['email' => 'noreason@example.test']);
        $admin = $this->makeInstitutionAdmin($institution);

        $payment = MemberPayment::create([
            'institution_id' => $institution->id,
            'student_id' => $member->studentRecord()->id,
            'user_id' => $member->id,
            'reference' => 'MP-TEST-0003',
            'amount' => 1000,
            'method' => 'cash',
            'status' => 'pending',
        ]);

        // The member sees the reason, so it is mandatory.
        $this->httpAs($admin)
            ->patch('/meals/member-payments/'.$payment->id.'/reject', [])
            ->assertSessionHasErrors('review_note');

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_a_payment_cannot_be_approved_twice(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution, ['email' => 'twice@example.test']);
        $admin = $this->makeInstitutionAdmin($institution);

        $payment = MemberPayment::create([
            'institution_id' => $institution->id,
            'student_id' => $member->studentRecord()->id,
            'user_id' => $member->id,
            'reference' => 'MP-TEST-0004',
            'amount' => 1000,
            'method' => 'cash',
            'status' => 'pending',
        ]);

        $this->httpAs($admin)->patch('/meals/member-payments/'.$payment->id.'/approve');

        $this->step('InstituteAdmin', 'Payments', 'a second approval is refused', __LINE__);

        // Double approval would credit the member twice.
        $this->httpAs($admin)
            ->patch('/meals/member-payments/'.$payment->id.'/approve')
            ->assertSessionHas('error');

        $this->assertSame(1, Deposit::where('student_id', $member->studentRecord()->id)->count());
    }

    public function test_the_member_payment_page_renders(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution, ['email' => 'view-pay@example.test']);

        $this->step('Member', 'Payments', 'visit /my/payments', __LINE__);

        $this->browse(function (Browser $browser) use ($member) {
            $browser->loginAs($member)
                ->visit('/my/payments')
                ->waitFor('[data-testid="member-payment-new"]', 20)
                ->click('[data-testid="member-payment-new"]')
                ->waitFor('[data-testid="member-payment-submit"]', 10)
                ->assertVisible('[data-testid="member-payment-submit"]');
        });
    }

    public function test_the_verification_queue_renders_for_a_manager(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution, ['email' => 'queue-pay@example.test']);
        $admin = $this->makeInstitutionAdmin($institution);

        MemberPayment::create([
            'institution_id' => $institution->id,
            'student_id' => $member->studentRecord()->id,
            'user_id' => $member->id,
            'reference' => 'MP-QUEUE-0001',
            'amount' => 2500,
            'method' => 'bkash',
            'status' => 'pending',
        ]);

        $this->step('InstituteAdmin', 'Payments', 'visit the verification queue', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/meals/member-payments')
                ->waitFor('[data-testid="payment-queue-row"]', 20)
                ->assertSee('MP-QUEUE-0001')
                ->assertVisible('[data-testid="payment-approve"]');
        });
    }

    public function test_a_member_cannot_open_the_staff_verification_queue(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution, ['email' => 'no-queue@example.test']);

        $this->step('Member', 'Payments', 'the staff queue is forbidden', __LINE__);

        // A member holds no meals.deposit permission.
        $this->httpAs($member)->get('/meals/member-payments')->assertForbidden();
    }
}
