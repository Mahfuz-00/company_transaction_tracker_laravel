<?php

namespace Tests\Browser\SoftwareSuperAdmin\Modules\InstitutionRegistry\List;

use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * SSA → MODULES → INSTITUTION REGISTRY → LIST.
 *
 * Route: GET /settings/institutions (`settings.institutions.index`), guarded by
 * `permission:institutions.view`. InstitutionRegistryController::index lists
 * EVERY institution (it runs inside TenantManager::runGlobally, the sanctioned
 * cross-tenant read).
 *
 * UI: resources/js/Pages/Settings/InstitutionRegistry.jsx -> heading "Institution
 * Registry", each card shows the name, member count and admins.
 */
class ListTest extends DuskTestCase
{
    use DuskSupport;
    use RefreshDatabase;

    public function test_ssa_sees_every_institution_on_the_platform(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        $north = $this->makeInstitution(['name' => 'North South University Dorm']);
        $this->makeInstitution(['name' => 'South College Mess']);

        // A member on one workspace, so the card can show a member count.
        Student::create(['institution_id' => $north->id, 'name' => 'North Member', 'status' => 'active']);

        $this->browse(function (Browser $browser) use ($ssa) {
            $this->step('SSA', 'InstitutionRegistry', 'visit /settings/institutions', __LINE__);

            $browser->loginAs($ssa)
                ->visit('/settings/institutions')
                ->waitForText('Institution Registry', 20);

            $this->step('SSA', 'InstitutionRegistry', 'assert both tenants listed', __LINE__);

            $browser->assertSee('North South University Dorm')
                ->assertSee('South College Mess');
        });
    }

    public function test_registry_search_filters_the_list(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();
        $this->makeInstitution(['name' => 'North South University Dorm']);
        $this->makeInstitution(['name' => 'South College Mess']);

        $this->browse(function (Browser $browser) use ($ssa) {
            $this->step('SSA', 'InstitutionRegistry', 'search "South"', __LINE__);

            // The search form submits ?search= to the same route.
            $browser->loginAs($ssa)
                ->visit('/settings/institutions?search=South')
                ->waitForText('Institution Registry', 20)
                ->assertSee('South College Mess')
                ->assertDontSee('North South University Dorm');
        });
    }
}
