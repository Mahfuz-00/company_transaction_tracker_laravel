<?php

namespace Tests\Browser\Finance;

use App\Models\Claim;
use App\Models\Deposit;
use App\Models\Transaction;
use App\Support\FinanceCalculator;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * Point 16: Approved Member Claims as Expenses & Fund Adjustment.
 *
 * Verifies:
 * - When a member's claim is approved by an institute admin or meal manager,
 *   that claim amount is counted as an expense incurred by the member.
 * - If adjusted, counted directly toward the member's money-in/deposit balance.
 */
class MemberClaimAdjustmentTest extends DuskTestCase
{
    use DuskSupport;

    public function test_approved_member_claim_creates_expense_and_credits_member_balance(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);
        $member = $this->makeMember($institution);
        $student = $member->studentRecord();

        // Member submits an expense claim for groceries purchased with personal funds
        $claim = Claim::create([
            'institution_id' => $institution->id,
            'student_id' => $student->id,
            'kind' => 'expense',
            'subject' => 'groceries',
            'amount' => 85.00,
            'title' => 'Bought spices and cooking oil',
            'description' => 'Meal manager was unavailable, purchased out of pocket.',
            'claim_date' => now()->toDateString(),
            'status' => 'pending',
        ]);

        $this->step('IA', 'Claims', 'approve member claim of $85', __LINE__);

        // Approve the claim
        $response = $this->httpAs($admin)->patch('/claims/'.$claim->id.'/approve', [
            'approved_amount' => 85.00,
            'review_notes' => 'Verified receipt, approved reimbursement to deposit balance.',
        ]);

        $response->assertSessionHas('success');

        // Claim must be marked approved
        $this->assertSame('approved', $claim->fresh()->status);

        // Verify transaction incurred by member (type 'out' from mess to member payee)
        $this->assertDatabaseHas('transactions', [
            'student_id' => $student->id,
            'amount' => 85.00,
            'payee' => $student->name,
            'source' => 'claim',
        ]);

        // Verify credit/deposit adjustment added to member's money-in balance
        $this->assertDatabaseHas('deposits', [
            'student_id' => $student->id,
            'amount' => 85.00,
            'kind' => 'credit',
            'payment_method' => 'Reimbursement',
        ]);

        // Verify analytics accounting does not duplicate expenses or double-count contributions
        $finance = new FinanceCalculator($institution);
        $month = now()->format('Y-m');
        $this->assertEquals(85.00, $finance->expensesForMonth($month));
        // Cash contributions do not double-count the member reimbursement
        $this->assertEquals(0.00, $finance->depositsForMonth($month));

        // Member's individual wallet balance is credited with the $85
        $breakdown = $finance->memberBreakdown($month);
        $memberRow = $breakdown->firstWhere('id', $student->id);
        $this->assertNotNull($memberRow);
        $this->assertEquals(85.00, $memberRow['deposited']);
        $this->assertEquals(85.00, $memberRow['balance']);
    }
}
