<?php

namespace Tests\Browser\InstituteAdmin\Modules\UserManager;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * INSTITUTE ADMIN → MODULES → USER MANAGER (TENANT-SCOPED).
 *
 * Route: GET /settings/users (`settings.users.index`), guarded by
 * `permission:users.view` AND `role:Software Super Admin|Institution Admin`.
 *
 * For an Institution Admin, UserController::index is TENANT-SCOPED: they see
 * only their own institution's users, and NEVER a global Super Admin account
 * (the query excludes users holding the global role and those with a NULL
 * institution_id). Cross-institution mutations are refused by canManageUser().
 */
class UserManagerTest extends DuskTestCase
{
    use DuskSupport;
    use RefreshDatabase;

    public function test_institute_admin_sees_only_their_institutions_users(): void
    {
        $this->seedRbac();
        $this->makeSuperAdmin(); // must NOT appear in an admin's roster

        $north = $this->makeInstitution(['name' => 'North South University Dorm']);
        $south = $this->makeInstitution(['name' => 'South College Mess']);

        $admin = $this->makeInstitutionAdmin($north, ['email' => 'admin@north.test']);
        $this->makeMealManager($north, ['email' => 'manager@north.test']);
        $this->makeMealManager($south, ['email' => 'manager@south.test']);

        $this->step('InstituteAdmin', 'UserManager', 'visit /settings/users', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/settings/users')
                ->waitForText('manager@north.test', 20);

            $this->step('InstituteAdmin', 'UserManager', 'assert cross-tenant + global accounts hidden', __LINE__);

            $browser->assertDontSee('manager@south.test')
                ->assertDontSee('ssa@platform.test');
        });
    }

    public function test_institute_admin_cannot_manage_a_user_from_another_institution(): void
    {
        $this->seedRbac();

        $north = $this->makeInstitution();
        $south = $this->makeInstitution();

        $admin = $this->makeInstitutionAdmin($north);
        $foreign = $this->makeMealManager($south);

        $this->step('InstituteAdmin', 'UserManager', 'PATCH deactivate a foreign user', __LINE__);

        $this->actingAs($admin)
            ->patch("/settings/users/{$foreign->id}/deactivate")
            ->assertSessionHas('error', 'That user belongs to another institution.');

        $this->assertSame('active', $foreign->fresh()->status);
    }
}
