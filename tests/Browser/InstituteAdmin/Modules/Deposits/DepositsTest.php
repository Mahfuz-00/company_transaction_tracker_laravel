<?php

namespace Tests\Browser\InstituteAdmin\Modules\Deposits;

use App\Models\Deposit;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * INSTITUTE ADMIN → MODULES → DEPOSITS.
 *
 * Routes (routes/web.php):
 *   GET  /meals/deposits                (`meals.deposits.index`,  meals.deposit)
 *   POST /meals/deposits                (`meals.deposits.store`,  meals.deposit)
 *   PATCH /meals/deposits/{id}/reverse  (`meals.deposits.reverse`, meals.deposit)
 *
 * DepositController::store records the cash-in ledger transaction AND the
 * Deposit row together; ::reverse keeps the row for history, flags it, and posts
 * a counter cash-out.
 */
class DepositsTest extends DuskTestCase
{
    use DuskSupport;

    public function test_institute_admin_records_a_deposit(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);
        $member = $this->makeStudent($institution, ['name' => 'Deposit Member', 'roll' => 'NSU-6001']);

        $this->step('InstituteAdmin', 'Deposits', 'POST a deposit', __LINE__);

        $this->httpAs($admin)
            ->post('/meals/deposits', [
                'student_id' => $member->id,
                'amount' => 1500,
                'payment_method' => 'Cash',
                'kind' => 'personal',
            ])
            ->assertSessionHas('success');

        $this->step('InstituteAdmin', 'Deposits', 'assert deposit + ledger row', __LINE__);

        $deposit = Deposit::where('student_id', $member->id)->first();
        $this->assertNotNull($deposit);
        $this->assertSame(1500.0, (float) $deposit->amount);
        $this->assertNotNull($deposit->transaction_id, 'A matching cash-in transaction must exist.');
    }

    public function test_institute_admin_reverses_a_deposit(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);
        $member = $this->makeStudent($institution, ['roll' => 'NSU-6002']);

        // Record via the real endpoint so the ledger link exists.
        $this->httpAs($admin)->post('/meals/deposits', [
            'student_id' => $member->id, 'amount' => 900, 'payment_method' => 'Cash',
        ]);

        $deposit = Deposit::where('student_id', $member->id)->firstOrFail();

        $this->step('InstituteAdmin', 'Deposits', 'PATCH reverse', __LINE__);

        $this->httpAs($admin)
            ->patch("/meals/deposits/{$deposit->id}/reverse")
            ->assertSessionHas('success');

        $this->step('InstituteAdmin', 'Deposits', 'assert reversed_at set', __LINE__);

        $this->assertNotNull($deposit->fresh()->reversed_at);
    }
}
