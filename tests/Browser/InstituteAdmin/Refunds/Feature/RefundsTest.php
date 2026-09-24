<?php

namespace Tests\Browser\InstituteAdmin\Refunds\Feature;

use App\Models\Deposit;
use App\Models\Refund;
use App\Models\Transaction;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * INSTITUTE ADMIN → MODULES → REFUNDS.
 *
 * Routes (routes/web.php, gated by `permission:meals.deposit`):
 *   GET   /meals/refunds                  (`meals.refunds.index`)
 *   POST  /meals/refunds                  (`meals.refunds.store`)
 *   PATCH /meals/refunds/{refund}/reverse (`meals.refunds.reverse`)
 *
 * A refund pays money back OUT of a member's balance and posts a matching
 * cash-out ledger transaction; reversing it keeps the row for audit and posts a
 * compensating cash-in. Refunds must NOT count as a mess expense (they would
 * otherwise inflate the per-meal rate).
 */
class RefundsTest extends DuskTestCase
{
    use DuskSupport;

    public function test_institute_admin_records_a_refund(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);
        $member = $this->makeStudent($institution, ['name' => 'Refund Member', 'roll' => 'NSU-7001']);

        // Fund the member first so there is credit to withdraw.
        Deposit::create([
            'institution_id' => $institution->id,
            'student_id' => $member->id,
            'amount' => 1000,
            'kind' => 'personal',
            'recorded_by' => $admin->id,
        ]);

        $this->step('InstituteAdmin', 'Refunds', 'POST a refund', __LINE__);

        $this->httpAs($admin)
            ->post('/meals/refunds', [
                'student_id' => $member->id,
                'amount' => 250,
                'reason' => 'withdrawal',
                'payment_method' => 'Cash',
            ])
            ->assertSessionHas('success');

        $this->step('InstituteAdmin', 'Refunds', 'assert refund + ledger row', __LINE__);

        $refund = Refund::where('student_id', $member->id)->first();
        $this->assertNotNull($refund);
        $this->assertSame(250.0, (float) $refund->amount);
        $this->assertNotNull($refund->transaction_id, 'A matching cash-out transaction must exist.');

        $tx = Transaction::find($refund->transaction_id);
        $this->assertSame('out', $tx->type);
        $this->assertSame('refund', $tx->source);

        // Balance = deposits − refunds (− meal cost at rate 0).
        $this->assertSame(750.0, $member->fresh()->balance(0.0));
    }

    public function test_institute_admin_reverses_a_refund(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);
        $member = $this->makeStudent($institution, ['roll' => 'NSU-7002']);

        Deposit::create([
            'institution_id' => $institution->id,
            'student_id' => $member->id,
            'amount' => 1000,
            'kind' => 'personal',
            'recorded_by' => $admin->id,
        ]);

        $this->httpAs($admin)->post('/meals/refunds', [
            'student_id' => $member->id, 'amount' => 300, 'reason' => 'stopped_meals',
        ]);

        $refund = Refund::where('student_id', $member->id)->firstOrFail();
        $this->assertSame(700.0, $member->fresh()->balance(0.0));

        $this->step('InstituteAdmin', 'Refunds', 'PATCH reverse', __LINE__);

        $this->httpAs($admin)
            ->patch("/meals/refunds/{$refund->id}/reverse")
            ->assertSessionHas('success');

        $this->step('InstituteAdmin', 'Refunds', 'assert reversed + balance restored', __LINE__);

        $reversed = $refund->fresh();
        $this->assertNotNull($reversed->reversed_at);
        $this->assertNotNull($reversed->reversal_transaction_id);
        $this->assertSame('in', Transaction::find($reversed->reversal_transaction_id)->type);
        $this->assertSame(1000.0, $member->fresh()->balance(0.0));
    }

    public function test_a_refund_cannot_exceed_the_members_available_balance(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);
        $member = $this->makeStudent($institution, ['roll' => 'NSU-7004']);

        Deposit::create([
            'institution_id' => $institution->id,
            'student_id' => $member->id,
            'amount' => 400,
            'kind' => 'personal',
            'recorded_by' => $admin->id,
        ]);

        $this->step('InstituteAdmin', 'Refunds', 'refuse an over-refund', __LINE__);

        $this->httpAs($admin)
            ->post('/meals/refunds', ['student_id' => $member->id, 'amount' => 900])
            ->assertSessionHas('error');

        $this->assertSame(0, Refund::where('student_id', $member->id)->count());
    }

    public function test_a_refund_does_not_inflate_the_per_meal_expense_figure(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);
        $member = $this->makeStudent($institution, ['roll' => 'NSU-7005']);
        $month = now()->format('Y-m');

        // A real meal expense and the member's credit.
        Transaction::create([
            'institution_id' => $institution->id,
            'user_id' => $admin->id,
            'item' => 'Groceries',
            'type' => 'out',
            'amount' => 600,
            'category' => 'Groceries',
            'payment_method' => 'Cash',
            'by_whom' => 'Vendor',
            'source' => 'meal_expense',
        ]);
        Deposit::create([
            'institution_id' => $institution->id,
            'student_id' => $member->id,
            'amount' => 2000,
            'kind' => 'personal',
            'recorded_by' => $admin->id,
        ]);

        $calculator = new \App\Support\FinanceCalculator($institution);
        $this->assertSame(600.0, $calculator->expensesForMonth($month));

        $this->httpAs($admin)->post('/meals/refunds', ['student_id' => $member->id, 'amount' => 200]);

        $this->step('InstituteAdmin', 'Refunds', 'refund must not count as expense', __LINE__);

        $this->assertSame(600.0, $calculator->expensesForMonth($month));
    }
}
