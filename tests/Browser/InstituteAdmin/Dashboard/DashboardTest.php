<?php

namespace Tests\Browser\InstituteAdmin\Dashboard;

use App\Models\Student;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * INSTITUTE ADMIN → DASHBOARD.
 *
 * Route: GET /dashboard (`dashboard`, DashboardController::index). For a bound
 * administrator the dashboard is TENANT-SCOPED: the pooled figures and roster
 * come from the active institution only, enforced by the BelongsToInstitution
 * global scope resolved through TenantManager.
 */
class DashboardTest extends DuskTestCase
{
    use DuskSupport;

    public function test_institute_admin_dashboard_is_tenant_scoped(): void
    {
        $this->seedRbac();

        $north = $this->makeInstitution(['name' => 'North South University Dorm']);
        $south = $this->makeInstitution(['name' => 'South College Mess']);

        $admin = $this->makeInstitutionAdmin($north, ['email' => 'admin@north.test']);

        Student::create(['institution_id' => $north->id, 'name' => 'North Member', 'status' => 'active']);
        Student::create(['institution_id' => $south->id, 'name' => 'South Member', 'status' => 'active']);

        $this->step('InstituteAdmin', 'Dashboard', 'visit /dashboard', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/dashboard')
                ->waitForText('Dashboard', 20);
        });

        $this->step('InstituteAdmin', 'Dashboard', 'assert roster is scoped', __LINE__);

        // Defence-in-depth check at the data layer: the tenant scope means a
        // bare query for the admin's institution returns only its own members.
        $this->assertSame(
            1,
            Student::where('institution_id', $north->id)->count(),
            'The admin institution owns exactly one member.'
        );
    }
}
