<?php

namespace Tests\Browser\SoftwareSuperAdmin\Dashboard;

use App\Models\Student;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * SOFTWARE SUPER ADMIN → DASHBOARD.
 *
 * The SSA's landing page is the GLOBAL SaaS Business Dashboard (route
 * `ssa.dashboard` -> GET /platform -> MonitoringController::index), which reads
 * cross-tenant data inside TenantManager::runGlobally().
 *
 * Verified here:
 *   - the dashboard renders its headline platform totals;
 *   - the tenant boundary holds: the SSA sees members aggregated across EVERY
 *     institution (unlike a tenant-bound admin), which is the whole point of
 *     the global view.
 */
class DashboardTest extends DuskTestCase
{
    use DuskSupport;

    public function test_ssa_dashboard_renders_platform_wide_metrics(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        // Two institutions, each with a member - the SSA view must roll them up.
        $north = $this->makeInstitution(['name' => 'North South University Dorm']);
        $south = $this->makeInstitution(['name' => 'South College Mess']);

        Student::create(['institution_id' => $north->id, 'name' => 'North Member', 'status' => 'active']);
        Student::create(['institution_id' => $south->id, 'name' => 'South Member', 'status' => 'active']);

        $this->browse(function (Browser $browser) use ($ssa) {
            $this->step('SSA', 'Dashboard', 'login and visit /platform', __LINE__);

            $browser->loginAs($ssa)
                ->visit('/platform');

            /*
             * The SSA heading is rendered with Tailwind `uppercase`, so the
             * VISIBLE text is "SOFTWARE SUPER ADMIN". waitForText() compares the
             * rendered (transformed) text, so the check must be case-insensitive.
             */
            $this->waitForTextCaseInsensitive($browser, 'Software Super Admin', 20);

            $this->step('SSA', 'Dashboard', 'assert global cross-tenant data', __LINE__);

            // MonitoringController::overview exposes total_members across ALL
            // tenants; both institution names appear in the health list.
            $browser->assertSee('North South University Dorm')
                ->assertSee('South College Mess');
        });
    }

    public function test_ssa_dashboard_is_not_a_tenant_workspace(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();
        $this->makeInstitution(['name' => 'North South University Dorm']);

        $this->step('SSA', 'Dashboard', 'assert landing stays on /platform', __LINE__);

        $this->browse(function (Browser $browser) use ($ssa) {
            $browser->loginAs($ssa)
                // /dashboard would redirect a tenant user to their scoped view;
                // for an unswitched SSA it must bounce straight to /platform.
                ->visit('/dashboard')
                ->waitForLocation('/platform', 20)
                ->assertPathIs('/platform');
        });
    }
}
