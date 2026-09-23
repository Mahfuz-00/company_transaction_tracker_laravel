<?php

namespace Tests\Browser\Member\Deposits\Feature;

use App\Models\Deposit;
use App\Models\Student;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * MEMBER → MODULES → MY DEPOSITS.
 *
 * Route: GET /my/deposits (`member.deposits`), gated by
 * `permission:meals.view` + `role:Member`.
 * MemberDashboardController::deposits scopes every row to the signed-in member.
 */
class DepositsTest extends DuskTestCase
{
    use DuskSupport;

    public function test_member_sees_their_own_deposit_history(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution, ['name' => 'Samira Member']);
        $memberRecord = Student::where('user_id', $member->id)->firstOrFail();

        Deposit::create([
            'student_id' => $memberRecord->id,
            'amount' => 1500,
            'kind' => 'personal',
            'payment_method' => 'Cash',
            'recorded_by' => $member->id,
        ]);

        $this->step('Member', 'Deposits', ' /my/deposits', __LINE__);

        $this->browse(function (Browser $browser) use ($member) {
            $browser->loginAs($member)
                ->visit('/my/deposits')
                ->assertPathIs('/my/deposits')
                ->assertSee('Samira Member');
        });
    }
}
