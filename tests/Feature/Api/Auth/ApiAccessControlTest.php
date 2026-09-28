<?php

namespace Tests\Feature\Api\Auth;

use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\ApiTestCase;

/**
 * MOBILE ACCESS CONTROL.
 *
 * Locks in the three-layer rule from the specification: the mobile app serves
 * exactly the three tenant roles (Institution Admin, Meal Manager, Member), and
 * the Software Super Admin is refused outright — at login, at /auth/me, AND by a
 * group-wide middleware on every protected route.
 *
 * These are the assertions that stop a future refactor from quietly re-opening
 * the cross-tenant data hole the SSA exclusion closes.
 */
class ApiAccessControlTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** An SSA can never mint a mobile token. */
    public function test_super_admin_cannot_log_in(): void
    {
        $ssa = User::factory()->create([
            'institution_id' => null,
            'email' => 'owner@platform.test',
            'password' => 'password',
            'status' => 'active',
            'must_change_password' => false,
            'setup_completed_at' => now(),
        ]);
        $ssa->syncRoles(['Software Super Admin']);

        $this->postJson('/api/auth/login', [
            'email' => 'owner@platform.test',
            'password' => 'password',
        ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Platform administrators must use the web console.');

        // No token was issued at all.
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /** A token that already exists for an SSA is refused on every protected call. */
    public function test_super_admin_token_is_refused_by_the_group_middleware(): void
    {
        $ssa = User::factory()->create([
            'institution_id' => null,
            'email' => 'owner@platform.test',
            'password' => 'password',
            'status' => 'active',
            'must_change_password' => false,
            'setup_completed_at' => now(),
        ]);
        $ssa->assignRole('Software Super Admin');

        Sanctum::actingAs($ssa);

        // /auth/me refuses …
        $this->getJson('/api/auth/me')->assertStatus(403);

        // … and so does every other protected endpoint, via the middleware. This
        // is the layer that matters: a token minted before the login guard — or
        // by any future auth path that forgets the check — still cannot read data.
        $this->getJson('/api/members')->assertStatus(403);
        $this->getJson('/api/dashboard')->assertStatus(403);
        $this->getJson('/api/me/dashboard')->assertStatus(403);
    }

    /** A Member can read their own area. */
    public function test_member_can_read_their_own_dashboard(): void
    {
        $institution = $this->makeInstitution();

        $user = $this->makeStaff($institution, ['email' => 'member@north.test']);
        // mimic a member linked to a roster row
        $user->syncRoles(['Member']);
        Student::create([
            'institution_id' => $institution->id,
            'user_id' => $user->id,
            'name' => 'A Member',
            'roll' => 'R-1',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/me/dashboard')
            ->assertOk()
            ->assertJsonPath('data.has_member_record', true)
            ->assertJsonPath('data.member.roll', 'R-1');
    }

    /** A Member cannot reach any administrative module — the web matrix holds. */
    public function test_member_cannot_read_the_staff_roster(): void
    {
        $institution = $this->makeInstitution();

        $user = $this->makeStaff($institution, ['email' => 'member@north.test']);
        // EXCLUSIVE role: a Member holds only meals.view / transactions.view /
        // claims.* / notifications.view. Nothing administrative.
        $user->syncRoles(['Member']);

        Sanctum::actingAs($user);

        // `students.view` is not held by a Member, so the route gate refuses it.
        $this->getJson('/api/members')->assertStatus(403);

        // Expenses require `meals.expense`, which a Member does not hold.
        $this->getJson('/api/expenses')->assertStatus(403);

        // Vendors require `vendors.view` — also not held.
        $this->getJson('/api/vendors')->assertStatus(403);

        // The review queue requires `claims.review`.
        $this->getJson('/api/claims/review')->assertStatus(403);

        // But their OWN claims list is fine (they hold claims.view).
        $this->getJson('/api/claims')->assertOk();

        // And their own personal area.
        $this->getJson('/api/me/deposits')->assertOk();
    }

    /**
     * A Meal Manager matches the web matrix EXACTLY.
     *
     * The manager holds the operational set (including students.view,
     * institution.view and the meals reports) but NOT the admin-only permissions:
     * institution.manage and notifications.announce.
     */
    public function test_meal_manager_access_matches_the_web_matrix(): void
    {
        $institution = $this->makeInstitution();

        $manager = $this->makeStaff($institution, ['email' => 'manager@north.test']);
        $manager->syncRoles(['Meal Manager']);

        Sanctum::actingAs($manager);

        // ---- Allowed: the operational set --------------------------------
        $this->getJson('/api/members')->assertOk();          // students.view
        $this->getJson('/api/meals/day')->assertOk();        // meals.view
        $this->getJson('/api/deposits')->assertOk();         // meals.deposit
        $this->getJson('/api/expenses')->assertOk();         // meals.expense
        $this->getJson('/api/vendors')->assertOk();          // vendors.view
        $this->getJson('/api/settings/institution')->assertOk(); // institution.view

        // ---- Refused: admin-only permissions -----------------------------
        // A manager holds institution.VIEW but not institution.MANAGE.
        $this->putJson('/api/settings/institution', ['name' => 'Hacked'])
            ->assertStatus(403);

        // `notifications.announce` is Institution-Admin-only; a manager holds
        // notifications.view and must NOT be able to broadcast.
        $this->postJson('/api/notifications/announce', ['title' => 'Hi', 'body' => 'There'])
            ->assertStatus(403);
    }

    /** An Institution Admin may manage the workspace. */
    public function test_institution_admin_can_manage_the_workspace(): void
    {
        $institution = $this->makeInstitution();

        $admin = $this->makeStaff($institution, ['email' => 'admin@north.test']);
        $admin->syncRoles(['Institution Admin']);

        Sanctum::actingAs($admin);

        $this->putJson('/api/settings/institution', ['name' => 'Renamed Hall'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Hall');

        $this->postJson('/api/notifications/announce', ['title' => 'Hello', 'body' => 'Everyone'])
            ->assertStatus(201);
    }

    /** Cross-tenant reads are impossible: a foreign member id is a 404. */
    public function test_a_member_from_another_institution_is_not_found(): void
    {
        $mine = $this->makeInstitution('My Hall');
        $theirs = $this->makeInstitution('Their Hall');

        $foreign = $this->makeMember($theirs, ['name' => 'Foreign Member']);

        $admin = $this->makeStaff($mine, ['email' => 'admin@mine.test']);
        $admin->syncRoles(['Institution Admin']);

        Sanctum::actingAs($admin);

        // Route-model binding is tenant-scoped, so this is a 404 — never another
        // workspace's row.
        $this->getJson("/api/members/{$foreign->id}")->assertStatus(404);
    }
}
