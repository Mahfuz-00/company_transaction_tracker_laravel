<?php

namespace Tests\Browser\InstituteAdmin\Modules\InviteCode;

use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * INSTITUTE ADMIN → MODULES → INVITE CODE (browser view).
 *
 * Route: GET /settings/invite-code (`settings.invite-code.show`), guarded by
 * `permission:institution.view`.
 *
 * This proves the missing admin-facing feature now renders in the browser: the
 * workspace's invite code and the shareable sign-up link are VISIBLE on screen
 * (InviteCode.jsx renders them with data-testid attributes), which is what an
 * admin needs in order to hand the code to members.
 *
 * The full generate -> register chain is covered deterministically by the
 * Feature test tests/Feature/Institution/InviteCodeTest.php (the registration
 * route sits behind `guest` middleware, which is cleaner to exercise at the HTTP
 * layer than through a shared Dusk session).
 */
class InviteCodeTest extends DuskTestCase
{
    use DuskSupport;

    public function test_institution_admin_sees_their_invite_code_on_screen(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['name' => 'North South University Dorm']);
        $admin = $this->makeInstitutionAdmin($institution, ['email' => 'admin@north.test']);

        $this->step('InstituteAdmin', 'InviteCode', 'visit /settings/invite-code', __LINE__);

        $this->browse(function (Browser $browser) use ($admin, $institution) {
            $browser->loginAs($admin)
                ->visit('/settings/invite-code')
                ->waitForText('Invite Code', 20);

            $this->step('InstituteAdmin', 'InviteCode', 'assert the code is rendered', __LINE__);

            // The code is shown in a [data-testid="invite-code"] element.
            $browser->assertVisible('[data-testid="invite-code"]')
                ->assertSee($institution->invite_code)
                // The shareable register link carries the same code.
                ->assertVisible('[data-testid="invite-link"]')
                ->assertSee('register');
        });
    }

    public function test_a_member_cannot_open_the_invite_code_screen(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution);

        $this->step('InstituteAdmin', 'InviteCode', 'assert member is forbidden', __LINE__);

        // institution.view is not held by a Member.
        $this->httpAs($member)->get('/settings/invite-code')->assertForbidden();
    }
}
