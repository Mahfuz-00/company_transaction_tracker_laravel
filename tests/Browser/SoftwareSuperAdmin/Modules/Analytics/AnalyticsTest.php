<?php

namespace Tests\Browser\SoftwareSuperAdmin\Modules\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * SSA → MODULES → SAAS ANALYTICS.
 *
 * Route: GET /platform/analytics (`ssa.analytics`), guarded by
 * `permission:monitoring.view`. SaaSAnalyticsController reports on the SAAS
 * BUSINESS (subscription revenue, conversion, retention) - not a tenant's meal
 * counts - and runs inside TenantManager::runGlobally().
 */
class AnalyticsTest extends DuskTestCase
{
    use DuskSupport;
    use RefreshDatabase;

    public function test_ssa_sees_platform_saas_analytics(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        // A paid tenant contributes MRR to the KPI band.
        $this->makeInstitution([
            'name' => 'North South University Dorm',
            'subscription_status' => 'paid',
            'subscription_amount' => 3000,
        ]);

        $this->step('SSA', 'Analytics', 'visit /platform/analytics', __LINE__);

        $this->browse(function (Browser $browser) use ($ssa) {
            $browser->loginAs($ssa)
                ->visit('/platform/analytics')
                ->assertPathIs('/platform/analytics')
                // The page renders under the SSA chrome with the platform sidebar.
                ->assertSee('Software Super Admin');
        });
    }
}
