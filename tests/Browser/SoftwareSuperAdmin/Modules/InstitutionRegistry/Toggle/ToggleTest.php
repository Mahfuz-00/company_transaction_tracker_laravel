<?php

namespace Tests\Browser\SoftwareSuperAdmin\Modules\InstitutionRegistry\Toggle;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * SSA → MODULES → INSTITUTION REGISTRY → TOGGLE (edit active state).
 *
 * Route: PATCH /settings/institutions/{institution}/toggle
 * (`settings.institutions.toggle`), guarded by `permission:institutions.manage`.
 * InstitutionRegistryController::toggle flips `institutions.is_active`.
 *
 * NOTE ON "DELETE": this codebase intentionally has NO institution-delete route
 * (a workspace holds financial history). The registry's destructive-equivalent
 * action is DEACTIVATE, which is why this leaf covers the toggle and asserts the
 * inactive state reaches the database.
 *
 * UI: the card button toggles between "Deactivate"/"Activate" and is confirmed
 * through the FeedbackProvider dialog ("Deactivate \"{name}\"?").
 */
class ToggleTest extends DuskTestCase
{
    use DuskSupport;
    use RefreshDatabase;

    public function test_ssa_deactivates_an_institution(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();
        $institution = $this->makeInstitution(['name' => 'North South University Dorm', 'is_active' => true]);

        $this->step('SSA', 'InstitutionRegistry', 'click Deactivate and confirm', __LINE__);

        $this->browse(function (Browser $browser) use ($ssa) {
            $browser->loginAs($ssa)
                ->visit('/settings/institutions')
                ->waitForText('North South University Dorm', 20)
                ->press('Deactivate')
                // The confirmer offers an explicit Deactivate confirm label.
                ->waitForText('Deactivate "North South University Dorm"?', 15)
                ->press('Deactivate')
                ->waitForText('is now inactive.', 15);
        });

        $this->step('SSA', 'InstitutionRegistry', 'assert is_active = false', __LINE__);

        $this->assertFalse($institution->fresh()->is_active);
    }
}
