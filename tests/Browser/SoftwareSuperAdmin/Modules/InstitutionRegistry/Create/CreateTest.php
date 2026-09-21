<?php

namespace Tests\Browser\SoftwareSuperAdmin\Modules\InstitutionRegistry\Create;

use App\Models\Institution;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * SSA → MODULES → INSTITUTION REGISTRY → CREATE.
 *
 * Route: POST /settings/institutions (`settings.institutions.store`), guarded by
 * `permission:institutions.manage`. InstitutionRegistryController::store
 * provisions the workspace AND its first Institution Admin together (via
 * InstitutionProvisioner::provision), so a workspace is never left admin-less.
 *
 * UI: the "New Institution" button opens a Modal containing form
 * #institution-create-form. Field ids come from the shared Field component
 * (id === name): #name, #type, #subtitle, #contact_email, #admin_name,
 * #admin_email, #admin_password. The modal's submit is the button with
 * form="institution-create-form".
 */
class CreateTest extends DuskTestCase
{
    use DuskSupport;

    public function test_ssa_creates_an_institution_with_its_first_admin(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        $this->step('SSA', 'InstitutionRegistry', 'open create modal and fill form', __LINE__);

        $this->browse(function (Browser $browser) use ($ssa) {
            $browser->loginAs($ssa)
                ->visit('/settings/institutions')
                ->waitForText('Institution Registry', 20)
                ->press('New Institution')
                ->waitFor('#institution-create-form', 15)
                ->type('#name', 'Riverside Corporate Canteen')
                // 'type' is a select rendered by Field with the Institution::TYPES keys.
                ->select('#type', 'company')
                ->type('#admin_name', 'Rahim Uddin')
                ->type('#admin_email', 'rahim@riverside.test')
                ->type('#admin_password', 'SecretPass123')
                // The footer submit is bound to the form by the `form` attribute.
                ->click('button[form="institution-create-form"]')
                ->waitForText('created with', 20);
        });

        $this->step('SSA', 'InstitutionRegistry', 'assert institution + admin persisted', __LINE__);

        $institution = Institution::where('name', 'Riverside Corporate Canteen')->first();
        $this->assertNotNull($institution);
        $this->assertSame('company', $institution->type);

        $admin = User::where('email', 'rahim@riverside.test')->first();
        $this->assertNotNull($admin);
        $this->assertSame($institution->id, $admin->institution_id);
        $this->assertTrue($admin->hasRole('Institution Admin'));
    }
}
