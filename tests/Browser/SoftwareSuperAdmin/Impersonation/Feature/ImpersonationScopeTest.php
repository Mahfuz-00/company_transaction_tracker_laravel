<?php

namespace Tests\Browser\SoftwareSuperAdmin\Impersonation\Feature;

use App\Models\Institution;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * SOFTWARE SUPER ADMIN → SWITCHED-IN WORKSPACE → CREDENTIAL SCOPE.
 *
 * THE BUG
 * -------
 * When an SSA clicked "Access Dashboard" to step INTO an institution, the shared
 * `auth.user` prop was still the OPERATOR. The management/profile forms in that
 * workspace read that prop, so they were pre-filled with the SSA's OWN name and
 * email - the operator's credentials bleeding into a workspace they were only
 * inspecting.
 *
 * THE FIX (two layers, both asserted here)
 * ----------------------------------------
 *  1. A `viewingAs` shared prop states WHO the shared auth user belongs to and
 *     whether the current view is an SSA managing ANOTHER tenant.
 *  2. Components that must never show operator credentials (the profile form, the
 *     user form's target-institution picker) key off it, and the UserController
 *     refuses cross-tenant writes from a switched-in view.
 */
class ImpersonationScopeTest extends DuskTestCase
{
    use DuskSupport;

    public function test_switching_into_an_institution_marks_the_view_as_impersonating(): void
    {
        $this->seedRbac();

        $ssa = $this->makeSuperAdmin();
        $target = $this->makeInstitution(['name' => 'Target Workspace']);

        $this->step('SoftwareSuperAdmin', 'Impersonation', 'switch into a workspace', __LINE__);

        $this->httpAs($ssa)
            ->patch('/settings/institutions/'.$target->slug.'/switch')
            ->assertSessionHas('success');

        // Now request a page and inspect the shared context the UI receives.
        $response = $this->httpAs($ssa)->get('/settings/users');

        $response->assertOk();

        $viewingAs = $response->viewData('page')['props']['viewingAs'] ?? null;
        $this->assertNotNull($viewingAs, 'The viewingAs context must be shared.');
        $this->assertTrue(
            $viewingAs['is_impersonating'],
            'A switched-in SSA must be flagged as impersonating.'
        );
        $this->assertSame($ssa->id, $viewingAs['operator_id']);
        $this->assertSame($target->id, $viewingAs['target_institution_id']);
    }

    public function test_an_unswitched_ssa_is_not_flagged_as_impersonating(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        $response = $this->httpAs($ssa)->get('/settings/users');
        $response->assertOk();

        $viewingAs = $response->viewData('page')['props']['viewingAs'] ?? null;

        $this->assertNotNull($viewingAs);
        $this->assertFalse(
            $viewingAs['is_impersonating'],
            'An SSA on the global platform view is not impersonating anyone.'
        );
    }

    public function test_the_ssa_profile_form_never_prefills_operator_credentials(): void
    {
        $this->seedRbac();

        $ssa = $this->makeSuperAdmin([
            'name' => 'Operator Name',
            'email' => 'operator@platform.test',
        ]);
        $target = $this->makeInstitution(['name' => 'Target Workspace']);

        $this->httpAs($ssa)->patch('/settings/institutions/'.$target->slug.'/switch');

        $this->step('SoftwareSuperAdmin', 'Impersonation', 'open the profile inside the switch', __LINE__);

        $this->browse(function (Browser $browser) use ($ssa) {
            $browser->loginAs($ssa)
                ->visit('/profile')
                ->waitFor('[data-testid="profile-context-notice"]', 20)
                // The guard notice is shown...
                ->assertVisible('[data-testid="profile-context-notice"]')
                // ...and the operator's own email is NOT sitting in an input field.
                ->assertMissing('input[value="operator@platform.test"]');
        });
    }

    public function test_the_user_manager_flags_the_managed_workspace(): void
    {
        $this->seedRbac();

        $ssa = $this->makeSuperAdmin();
        $target = $this->makeInstitution(['name' => 'Managed Workspace']);

        // A real admin inside the target workspace, so the directory is populated.
        $this->makeInstitutionAdmin($target, ['email' => 'target-admin@workspace.test']);

        $this->httpAs($ssa)->patch('/settings/institutions/'.$target->slug.'/switch');

        $this->step('SoftwareSuperAdmin', 'Impersonation', 'open the user manager inside the switch', __LINE__);

        $this->browse(function (Browser $browser) use ($ssa) {
            $browser->loginAs($ssa)
                ->visit('/settings/users')
                ->waitFor('[data-testid="management-context-banner"]', 20)
                ->assertVisible('[data-testid="management-context-banner"]')
                ->assertSee('Managed Workspace');
        });
    }

    public function test_a_switched_ssa_cannot_write_to_a_different_tenant(): void
    {
        $this->seedRbac();

        $ssa = $this->makeSuperAdmin();

        $active = $this->makeInstitution(['name' => 'Active Workspace']);
        $other = $this->makeInstitution(['name' => 'Other Workspace']);

        // An admin in a DIFFERENT workspace than the one the SSA switched into.
        $foreignAdmin = $this->makeInstitutionAdmin($other, ['email' => 'foreign@other.test']);

        // Switch the SSA into `active`.
        $this->httpAs($ssa)->patch('/settings/institutions/'.$active->slug.'/switch');

        $this->step('SoftwareSuperAdmin', 'Impersonation', 'attempt a cross-tenant user update', __LINE__);

        // The management view belongs to `active`; touching `other`'s admin must be
        // refused, so an operator can never cross-contaminate workspaces.
        $this->httpAs($ssa)
            ->put('/settings/users/'.$foreignAdmin->id, [
                'name' => 'Hijacked Name',
                'email' => 'foreign@other.test',
                'status' => 'active',
                'roles' => [],
            ])
            ->assertSessionHas('error');

        $this->assertSame(
            $foreignAdmin->getOriginal('name'),
            $foreignAdmin->fresh()->name,
            'The foreign tenant admin must be untouched.'
        );
    }

    public function test_an_institution_admin_never_sees_the_global_operator_in_the_directory(): void
    {
        $this->seedRbac();

        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $ssa = $this->makeSuperAdmin([
            'name' => 'Global Operator',
            'email' => 'operator@platform.test',
        ]);

        $this->step('InstitutionAdmin', 'Impersonation', 'the SSA must not appear in the roster', __LINE__);

        // An Institution Admin's User Manager must never list a global SSA account,
        // so operator credentials can never be exposed to (or edited by) a tenant.
        $this->httpAs($admin)
            ->get('/settings/users')
            ->assertOk()
            ->assertDontSee('operator@platform.test');
    }

    public function test_exiting_the_tenant_clears_the_impersonation_flag(): void
    {
        $this->seedRbac();

        $ssa = $this->makeSuperAdmin();
        $target = $this->makeInstitution();

        $this->httpAs($ssa)->patch('/settings/institutions/'.$target->slug.'/switch');
        $this->httpAs($ssa)->post('/settings/institutions/exit');

        $response = $this->httpAs($ssa)->get('/settings/users');
        $response->assertOk();

        $viewingAs = $response->viewData('page')['props']['viewingAs'] ?? null;

        $this->assertFalse(
            $viewingAs['is_impersonating'],
            'Leaving the workspace must clear the impersonation flag.'
        );
    }
}
