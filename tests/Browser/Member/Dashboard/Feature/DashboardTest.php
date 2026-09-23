<?php

namespace Tests\Browser\Member\Dashboard\Feature;

use App\Models\Deposit;
use App\Models\Student;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * MEMBER → DASHBOARD.
 *
 * Route: GET /my/dashboard (`member.dashboard`), gated by
 * `permission:meals.view` AND `role:Member`, and strictly personal: every figure
 * is scoped to the signed-in member's own Student record
 * (MemberDashboardController::index -> User::studentRecord()).
 *
 * This is the strongest isolation guarantee in the app: a member must never see
 * another member's record NOR the institution's pooled figures.
 */
class DashboardTest extends DuskTestCase
{
    use DuskSupport;

    public function test_member_dashboard_shows_only_their_own_data(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['name' => 'North South University Dorm']);

        $member = $this->makeMember($institution, ['name' => 'Samira Member', 'email' => 'member@north.test']);
        $memberRecord = Student::where('user_id', $member->id)->firstOrFail();

        // The member's own deposit.
        Deposit::create([
            'student_id' => $memberRecord->id,
            'amount' => 2000,
            'kind' => 'personal',
            'payment_method' => 'Cash',
            'recorded_by' => $member->id,
        ]);

        // A DIFFERENT member in the same institution - must never appear.
        $other = $this->makeMember($institution, ['name' => 'Other Member', 'email' => 'other@north.test']);

        $this->step('Member', 'Dashboard', 'visit /my/dashboard', __LINE__);

        $this->browse(function (Browser $browser) use ($member) {
            $browser->loginAs($member)
                ->visit('/my/dashboard')
                ->waitForText('Samira Member', 20)
                ->assertSee('Samira Member');

            $this->step('Member', 'Dashboard', 'assert other member is invisible', __LINE__);

            $browser->assertDontSee('Other Member');
        });
    }

    public function test_member_cannot_reach_administrative_consoles(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution);

        $this->step('Member', 'Dashboard', 'assert admin modules are forbidden', __LINE__);

        // The User Manager requires an admin role; the SSA console requires
        // monitoring.view - a Member holds neither.
        $this->httpAs($member)->get('/settings/users')->assertForbidden();
        $this->httpAs($member)->get('/platform')->assertForbidden();
    }
}
