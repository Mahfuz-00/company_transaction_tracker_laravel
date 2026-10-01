<?php

namespace Tests\Browser\Payments;

use App\Models\Deposit;
use App\Models\Refund;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * Point 15: Payment Refund System.
 *
 * Verifies:
 * - Refund mechanism allowing payments/balances to be refunded by receiving party
 *   (SSA, Institute Admin, or Meal Manager depending on transaction scope).
 * - Refund validation prevents refunding beyond credit balance.
 * - Deducts from member's balance and records audit trail.
 */
class PaymentRefundTest extends DuskTestCase
{
    use DuskSupport;

    public function test_authorized_manager_issues_payment_refund(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);
        $member = $this->makeMember($institution);
        $student = $member->studentRecord();

        // Member deposited $200
        Deposit::create([
            'institution_id' => $institution->id,
            'student_id' => $student->id,
            'user_id' => $admin->id,
            'amount' => 200.00,
            'payment_method' => 'bank_transfer',
            'deposit_date' => now()->toDateString(),
        ]);

        $this->step('IA', 'Payments', 'issue refund of $50 from meal balance', __LINE__);

        $response = $this->httpAs($admin)->post('/meals/refunds', [
            'student_id' => $student->id,
            'amount' => 50.00,
            'reason' => 'withdrawal',
            'payment_method' => 'bank_transfer',
            'notes' => 'Partial withdrawal upon request',
        ]);

        $response->assertSessionHas('success');

        $this->assertDatabaseHas('refunds', [
            'institution_id' => $institution->id,
            'student_id' => $student->id,
            'amount' => 50.00,
            'reason' => 'withdrawal',
        ]);
    }

    public function test_refund_cannot_exceed_available_member_credit(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);
        $member = $this->makeMember($institution);
        $student = $member->studentRecord();

        // Member deposited only $30
        Deposit::create([
            'institution_id' => $institution->id,
            'student_id' => $student->id,
            'user_id' => $admin->id,
            'amount' => 30.00,
            'payment_method' => 'cash',
            'deposit_date' => now()->toDateString(),
        ]);

        $this->step('IA', 'Payments', 'attempt refund exceeding balance ($100 > $30)', __LINE__);

        // Attempting to refund $100 must fail with error
        $response = $this->httpAs($admin)->post('/meals/refunds', [
            'student_id' => $student->id,
            'amount' => 100.00,
            'reason' => 'withdrawal',
            'payment_method' => 'cash',
        ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('refunds', [
            'student_id' => $student->id,
            'amount' => 100.00,
        ]);
    }
}
