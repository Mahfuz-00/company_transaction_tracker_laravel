<?php

namespace Tests\Browser\SoftwareSuperAdmin\Modules\InstitutionRegistry\Switch;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * SSA → MODULES → INSTITUTION REGISTRY → ACCESS DASHBOARD (switch tenant).
 *
 * Route: PATCH /settings/institutions/{institution}/switch
 * (`settings.institutions.switch`), guarded by `permission:institutions.manage`.
 * InstitutionRegistryController::switchTo stores `tenant_id` in the SESSION
 * (never a global flag), then redirects to the tenant's member roster.
 *
 * This is the SSA's only sanctioned way into a tenant's operational modules. The
 * route key is the institution SLUG (Institution::getRouteKeyName() === 'slug').
 */
class SwitchTest extends DuskTestCase
{
    use DuskSupport;
    use RefreshDatabase;

    public function test_ssa_switches_into_an_institution_workspace(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();
        $institution = $this->makeInstitution([
            'name' => 'North South University Dorm',
            'slug' => 'north-south-university-dorm',
        ]);

        $this->step('SSA', 'InstitutionRegistry', 'click Access Dashboard', __LINE__);

        $this->browse(function (Browser $browser) use ($ssa) {
            $browser->loginAs($ssa)
                ->visit('/settings/institutions')
                ->waitForText('North South University Dorm', 20)
                ->press('Access Dashboard')
                // switchTo() redirects to meals.students.index with a success flash.
                ->waitForText('Now viewing "North South University Dorm".', 20)
                ->assertPathIs('/meals/students');
        });

        $this->step('SSA', 'InstitutionRegistry', 'assert no global flag was mutated', __LINE__);

        // The switch is session-scoped: the institution row stays active.
        $this->assertTrue($institution->fresh()->is_active);
    }
}
