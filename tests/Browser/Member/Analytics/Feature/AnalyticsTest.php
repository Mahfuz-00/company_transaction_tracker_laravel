<?php

namespace Tests\Browser\Member\Analytics\Feature;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * MEMBER → MODULES → MY ANALYTICS.
 *
 * Route: GET /my/analytics (`member.analytics`), gated by
 * `permission:meals.view` + `role:Member`.
 * MemberDashboardController::analytics reports ONLY this member's own month
 * trend and meal split - never the institution's pooled figures.
 */
class AnalyticsTest extends DuskTestCase
{
    use DuskSupport;

    public function test_member_opens_their_personal_analytics(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution, ['name' => 'Samira Member']);

        $this->step('Member', 'Analytics', 'visit /my/analytics', __LINE__);

        $this->browse(function (Browser $browser) use ($member) {
            $browser->loginAs($member)
                ->visit('/my/analytics')
                ->assertPathIs('/my/analytics')
                ->assertSee('Samira Member');
        });
    }
}
