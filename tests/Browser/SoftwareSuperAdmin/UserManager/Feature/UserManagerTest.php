<?php

namespace Tests\Browser\SoftwareSuperAdmin\UserManager\Feature;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * SSA → MODULES → USER MANAGER (GLOBAL DIRECTORY).
 *
 * Route: GET /settings/users (`settings.users.index`), guarded by
 * `permission:users.view` AND `role:Software Super Admin|Institution Admin`.
 *
 * UserController::index switches to the GLOBAL directory for an SSA: it lists
 * users across EVERY institution (run inside TenantManager::runGlobally). The
 * SSA can also create a user against any target institution (`institution_id`).
 * Crucially, an Institution Admin's scope excludes global SSA accounts - tested
 * in the InstituteAdmin suite.
 */
class UserManagerTest extends DuskTestCase
{
    use DuskSupport;

    public function test_ssa_sees_the_global_user_directory(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        $north = $this->makeInstitution(['name' => 'North South University Dorm']);
        $south = $this->makeInstitution(['name' => 'South College Mess']);

        $this->makeInstitutionAdmin($north, ['email' => 'north-admin@example.test']);
        $this->makeMealManager($south, ['email' => 'south-manager@example.test']);

        $this->step('SSA', 'UserManager', 'visit /settings/users', __LINE__);

        $this->browse(function (Browser $browser) use ($ssa) {
            $browser->loginAs($ssa)
                ->visit('/settings/users')
                ->waitForText('north-admin@example.test', 20)
                // Global scope: users from a DIFFERENT institution are visible.
                ->assertSee('south-manager@example.test');
        });
    }

    public function test_ssa_creates_a_user_in_a_target_institution(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        $north = $this->makeInstitution(['name' => 'North South University Dorm']);

        $this->step('SSA', 'UserManager', 'POST a user into a target institution', __LINE__);

        // UserController::store - SSA mode accepts an explicit institution_id and
        // a 'password' creation mode (which forces a change on first login).
        $this->httpAs($ssa)
            ->post('/settings/users', [
                'name' => 'Target User',
                'email' => 'target-user@example.test',
                'status' => 'active',
                'roles' => ['Meal Manager'],
                'creation_mode' => 'password',
                'password' => 'TempPass123',
                'password_confirmation' => 'TempPass123',
                'institution_id' => $north->id,
            ])
            ->assertSessionHas('success');

        $this->step('SSA', 'UserManager', 'assert user bound to the target institution', __LINE__);

        $user = User::where('email', 'target-user@example.test')->first();
        $this->assertNotNull($user);
        $this->assertSame($north->id, $user->institution_id);
        $this->assertTrue($user->hasRole('Meal Manager'));
    }

    public function test_global_role_cannot_be_granted_through_the_institution_flow(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();
        $north = $this->makeInstitution();

        $this->step('SSA', 'UserManager', 'crafted global role collapses to Member', __LINE__);

        // syncInstitutionRoles() strips 'Software Super Admin' - defence in depth
        // against privilege escalation via a crafted roles[] payload.
        $this->httpAs($ssa)
            ->post('/settings/users', [
                'name' => 'Escalation Attempt',
                'email' => 'escalate@example.test',
                'status' => 'active',
                'roles' => ['Software Super Admin'],
                'creation_mode' => 'password',
                'password' => 'TempPass123',
                'password_confirmation' => 'TempPass123',
                'institution_id' => $north->id,
            ]);

        $user = User::where('email', 'escalate@example.test')->first();
        $this->assertNotNull($user);
        $this->assertFalse($user->hasRole('Software Super Admin'));
        $this->assertTrue($user->hasRole('Member'));
    }
}
