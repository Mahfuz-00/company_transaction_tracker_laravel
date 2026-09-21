<?php

namespace Tests\Feature\Institution;

use App\Models\Institution;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * INSTITUTION INVITE CODE - admin generates/views, member registers with it.
 *
 * This locks in the feature end-to-end at the HTTP layer:
 *   1. An Institution Admin can VIEW their workspace's invite code and the
 *      shareable sign-up link (GET /settings/invite-code).
 *   2. The admin can ROTATE the code (POST /settings/invite-code/regenerate),
 *      and the old code stops working immediately.
 *   3. A GUEST registers with that code and is mapped onto the right institution
 *      (POST /register), proving the code produced by the admin actually works.
 *   4. Tenancy: an admin can never view or rotate ANOTHER institution's code.
 *
 * Routes (added to routes/web.php, Workspace Settings area):
 *   GET  /settings/invite-code              -> settings.invite-code.show       (institution.view)
 *   POST /settings/invite-code/regenerate   -> settings.invite-code.regenerate (institution.manage)
 */
class InviteCodeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The RBAC backbone the route middleware and app guards depend on.
     */
    protected function seedRbac(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function makeInstitution(array $attributes = []): Institution
    {
        return Institution::create(array_merge([
            'name' => 'North South University Dorm',
            'type' => 'university_dorm',
            'timezone' => 'UTC',
            'is_active' => true,
            'onboarding_mode' => 'subscription',
            'subscription_status' => 'paid',
        ], $attributes));
    }

    protected function makeInstitutionAdmin(Institution $institution, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'institution_id' => $institution->id,
            'name' => 'Institution Admin',
            'email' => 'admin@north.test',
            'password' => 'password',
            'status' => 'active',
            'must_change_password' => false,
            'setup_completed_at' => now(),
        ], $attributes));

        $user->assignRole('Institution Admin');

        return $user;
    }

    public function test_every_institution_is_created_with_an_invite_code(): void
    {
        $this->seedRbac();

        $institution = $this->makeInstitution();

        // Institution::booted() assigns a unique code on first save.
        $this->assertNotEmpty($institution->invite_code);
        $this->assertSame(8, strlen($institution->invite_code));
    }

    public function test_institution_admin_can_view_their_invite_code(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $response = $this->actingAs($admin)->get('/settings/invite-code');

        $response->assertOk();
        // The page receives the real code + a register link carrying it.
        $response->assertInertia(fn ($page) => $page
            ->component('Settings/InviteCode')
            ->where('inviteCode', $institution->invite_code)
            ->where('institution.name', 'North South University Dorm')
            ->where('canManage', true)
        );
    }

    public function test_institution_admin_can_rotate_the_invite_code(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $original = $institution->invite_code;

        $this->actingAs($admin)
            ->post('/settings/invite-code/regenerate')
            ->assertSessionHas('success');

        $new = $institution->fresh()->invite_code;

        $this->assertNotSame($original, $new, 'Rotating must produce a different code.');
        $this->assertNotEmpty($new);
        // The OLD code no longer resolves to the institution.
        $this->assertNull(Institution::findByInviteCode($original));
        $this->assertSame($institution->id, Institution::findByInviteCode($new)?->id);
    }

    public function test_a_member_registers_with_the_code_generated_by_the_admin(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['name' => 'Acme Foods Cafeteria', 'type' => 'company']);
        $admin = $this->makeInstitutionAdmin($institution);

        // The admin views the code...
        $this->actingAs($admin)->get('/settings/invite-code')->assertOk();
        $code = $institution->fresh()->invite_code;

        // ...then the admin steps OUT so a GUEST can use that exact code. The
        // /register route is behind `guest` middleware, so an authenticated
        // session would just be redirected instead of registering.
        $this->post('/logout');
        $this->assertGuest();

        $response = $this->post('/register', [
            'name' => 'New Member',
            'email' => 'newbie@acme.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'invite_code' => $code,
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticated();

        $member = User::where('email', 'newbie@acme.test')->first();
        $this->assertNotNull($member);
        // Mapped onto the RIGHT institution, and only ever as a Member.
        $this->assertSame($institution->id, $member->institution_id);
        $this->assertTrue($member->hasRole('Member'));
        $this->assertFalse($member->hasRole('Software Super Admin'));
    }

    public function test_registration_with_a_rotated_old_code_is_rejected(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $oldCode = $institution->invite_code;
        $this->actingAs($admin)->post('/settings/invite-code/regenerate');

        // Log out so the guest registration below is evaluated by `guest`
        // middleware (an authenticated session would be redirected instead).
        $this->post('/logout');
        $this->assertGuest();

        // The guest still holds the stale code -> registration must fail cleanly.
        $this->post('/register', [
            'name' => 'Stale Code User',
            'email' => 'stale@north.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'invite_code' => $oldCode,
        ])->assertSessionHasErrors('invite_code');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'stale@north.test']);
    }

    public function test_an_admin_cannot_view_another_institutions_invite_code(): void
    {
        $this->seedRbac();

        $north = $this->makeInstitution(['name' => 'North Dorm']);
        $south = $this->makeInstitution(['name' => 'South Mess']);

        $northAdmin = $this->makeInstitutionAdmin($north, ['email' => 'admin@north.test']);

        /*
         * The code shown to an admin is always the ACTIVE tenant's (their own).
         * There is no query parameter to point it at another institution; the
         * controller resolves Institution::current() and asserts ownership, so
         * the south admin's code can never be surfaced to the north admin.
         */
        $this->actingAs($northAdmin)
            ->get('/settings/invite-code')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('inviteCode', $north->invite_code)
                ->where('institution.name', 'North Dorm')
            );

        $this->assertNotSame($north->invite_code, $south->invite_code);
    }

    /** A signed-in Member must NOT be able to rotate the code. */
    public function test_a_member_cannot_rotate_the_invite_code(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();

        $member = User::factory()->create([
            'institution_id' => $institution->id,
            'email' => 'member@north.test',
            'password' => 'password',
            'status' => 'active',
            'setup_completed_at' => now(),
        ]);
        $member->assignRole('Member');

        // `institution.view` / `institution.manage` are not held by a Member.
        $this->actingAs($member)->get('/settings/invite-code')->assertForbidden();
        $this->actingAs($member)->post('/settings/invite-code/regenerate')->assertForbidden();
    }
}
