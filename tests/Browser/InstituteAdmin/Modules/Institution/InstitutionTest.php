<?php

namespace Tests\Browser\InstituteAdmin\Modules\Institution;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * INSTITUTE ADMIN → MODULES → INSTITUTION SETTINGS.
 *
 * Routes: GET /settings/institution (`settings.institution.edit`,
 * `permission:institution.view`) and PUT /settings/institution
 * (`settings.institution.update`, `permission:institution.manage`).
 *
 * InstitutionController manages the workspace's type, terminology, identity and
 * branding. The institution is resolved from the ACTIVE tenant, so an admin can
 * only ever edit their own.
 */
class InstitutionTest extends DuskTestCase
{
    use DuskSupport;

    public function test_institute_admin_opens_institution_settings(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['name' => 'North South University Dorm']);
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('InstituteAdmin', 'Institution', 'visit /settings/institution', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/settings/institution')
                ->assertPathIs('/settings/institution')
                ->assertSee('North South University Dorm');
        });
    }
}
