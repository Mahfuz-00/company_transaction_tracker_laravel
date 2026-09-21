<?php

namespace Tests\Browser\SoftwareSuperAdmin\Modules\Monitoring;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * SSA → MODULES → BUSINESS MONITORING.
 *
 * Route: GET /settings/monitoring (`settings.monitoring.index`), guarded by
 * `permission:monitoring.view` and re-asserted via isSuperAdmin() in
 * MonitoringController::index.
 *
 * This is the cross-tenant control tower: every aggregate runs inside
 * TenantManager::runGlobally(). It exposes a subscription update action
 * (PUT /settings/monitoring/{institution}/subscription) and an audit export.
 */
class MonitoringTest extends DuskTestCase
{
    use DuskSupport;
    use RefreshDatabase;

    public function test_ssa_sees_the_platform_control_tower(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();
        $this->makeInstitution(['name' => 'North South University Dorm']);

        $this->step('SSA', 'Monitoring', 'visit /settings/monitoring', __LINE__);

        $this->browse(function (Browser $browser) use ($ssa) {
            $browser->loginAs($ssa)
                ->visit('/settings/monitoring')
                ->waitForText('Software Super Admin', 20)
                ->assertSee('North South University Dorm');
        });
    }

    public function test_ssa_updates_an_institutions_subscription(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();
        $institution = $this->makeInstitution([
            'name' => 'North South University Dorm',
            'subscription_status' => 'trial',
        ]);

        $this->step('SSA', 'Monitoring', 'PUT subscription status = paid', __LINE__);

        // MonitoringController::updateSubscription validates against
        // Institution::SUBSCRIPTION_STATUSES keys.
        $this->actingAs($ssa)
            ->put("/settings/monitoring/{$institution->slug}/subscription", [
                'subscription_status' => 'paid',
                'subscription_amount' => 1500,
                'member_limit' => 200,
            ])
            ->assertSessionHas('success');

        $this->step('SSA', 'Monitoring', 'assert subscription persisted', __LINE__);

        $institution->refresh();
        $this->assertSame('paid', $institution->subscription_status);
        $this->assertSame('1500.00', $institution->subscription_amount);
        $this->assertSame(200, $institution->member_limit);
    }

    public function test_a_tenant_admin_cannot_reach_the_control_tower(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('SSA', 'Monitoring', 'assert tenant admin is forbidden', __LINE__);

        // `permission:monitoring.view` is held by the global role ONLY.
        $this->actingAs($admin)->get('/settings/monitoring')->assertForbidden();
    }
}
