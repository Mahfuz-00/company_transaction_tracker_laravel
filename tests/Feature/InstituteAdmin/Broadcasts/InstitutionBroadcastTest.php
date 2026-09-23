<?php

namespace Tests\Feature\InstituteAdmin\Broadcasts;

use App\Models\Institution;
use App\Models\InstitutionBroadcast;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * INSTITUTION-SCOPED BROADCASTS — the ISOLATION guarantee.
 *
 * This is the runnable proof (no browser needed) that a workspace broadcast
 * reaches ONLY the sending institution:
 *
 *   - the history row is stamped with the admin's own institution_id;
 *   - users of that institution receive an in-app notification;
 *   - users of a SECOND institution receive NONE;
 *   - a Meal Manager (no `notifications.announce`) is refused;
 *   - the history view shows only the actor's institution.
 *
 * Routes: GET/POST /settings/broadcasts (`broadcasts.index` / `broadcasts.store`),
 * both gated by `permission:notifications.announce`.
 */
class InstitutionBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected function seedRbac(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function makeInstitution(string $name): Institution
    {
        return Institution::create([
            'name' => $name,
            'type' => 'university_dorm',
            'timezone' => 'UTC',
            'is_active' => true,
            'onboarding_mode' => 'subscription',
            'subscription_status' => 'paid',
        ]);
    }

    protected function makeTenantUser(Institution $institution, string $role, string $email, string $name = 'Tenant User'): User
    {
        $user = User::factory()->create([
            'institution_id' => $institution->id,
            'name' => $name,
            'email' => $email,
            'password' => 'password',
            'status' => 'active',
            'must_change_password' => false,
            'setup_completed_at' => now(),
        ]);

        $user->assignRole($role);

        return $user;
    }

    public function test_an_institution_admin_broadcasts_only_within_their_institution(): void
    {
        $this->seedRbac();

        $alpha = $this->makeInstitution('Alpha Dorm');
        $beta = $this->makeInstitution('Beta Mess');

        $alphaAdmin = $this->makeTenantUser($alpha, 'Institution Admin', 'admin@alpha.test', 'Alpha Admin');
        $alphaMember = $this->makeTenantUser($alpha, 'Member', 'member@alpha.test', 'Alpha Member');
        // A second workspace: nobody here may receive the Alpha broadcast.
        $betaMember = $this->makeTenantUser($beta, 'Member', 'member@beta.test', 'Beta Member');

        $this->actingAs($alphaAdmin)
            ->post('/settings/broadcasts', [
                'title' => 'Menu change from Monday',
                'body' => 'Lunch moves to 13:00 for the rest of the term.',
                'audience' => 'members',
                'severity' => 'info',
            ])
            ->assertSessionHas('success');

        // 1. The history row belongs to the ACTOR's institution.
        $this->assertDatabaseHas('institution_broadcasts', [
            'title' => 'Menu change from Monday',
            'audience' => 'members',
            'institution_id' => $alpha->id,
            'sent_by' => $alphaAdmin->id,
        ]);

        // 2. The Alpha member was notified.
        $this->assertSame(1, $alphaMember->fresh()->unreadNotifications()->count());

        // 3. The Beta member was NOT — this is the isolation guarantee.
        $this->assertSame(0, $betaMember->fresh()->unreadNotifications()->count());

        // 4. The sender never notifies themselves.
        $this->assertSame(0, $alphaAdmin->fresh()->unreadNotifications()->count());
    }

    public function test_a_crafted_request_cannot_target_another_institution(): void
    {
        $this->seedRbac();

        $alpha = $this->makeInstitution('Alpha Dorm');
        $beta = $this->makeInstitution('Beta Mess');

        $alphaAdmin = $this->makeTenantUser($alpha, 'Institution Admin', 'admin@alpha.test', 'Alpha Admin');
        $betaMember = $this->makeTenantUser($beta, 'Member', 'member@beta.test', 'Beta Member');

        // Attempt to redirect the broadcast at Beta via request input.
        $this->actingAs($alphaAdmin)
            ->post('/settings/broadcasts', [
                'title' => 'Malicious',
                'body' => 'Try to cross tenants.',
                'audience' => 'members',
                'severity' => 'info',
                'institution_id' => $beta->id,
            ])
            ->assertSessionHas('success');

        // The row is STILL stamped with Alpha — request input is ignored.
        $this->assertDatabaseHas('institution_broadcasts', [
            'title' => 'Malicious',
            'institution_id' => $alpha->id,
        ]);
        $this->assertDatabaseMissing('institution_broadcasts', [
            'title' => 'Malicious',
            'institution_id' => $beta->id,
        ]);

        // And nobody in Beta heard about it.
        $this->assertSame(0, $betaMember->fresh()->unreadNotifications()->count());
    }

    public function test_a_meal_manager_cannot_send_a_broadcast(): void
    {
        $this->seedRbac();

        $alpha = $this->makeInstitution('Alpha Dorm');
        $manager = $this->makeTenantUser($alpha, 'Meal Manager', 'mm@alpha.test', 'Meal Manager');

        // `notifications.announce` is not held by a Meal Manager.
        $this->actingAs($manager)->get('/settings/broadcasts')->assertForbidden();
        $this->actingAs($manager)
            ->post('/settings/broadcasts', [
                'title' => 'Nope',
                'body' => 'Not allowed.',
                'audience' => 'members',
                'severity' => 'info',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('institution_broadcasts', 0);
    }

    public function test_the_history_view_shows_only_the_actors_institution(): void
    {
        $this->seedRbac();

        $alpha = $this->makeInstitution('Alpha Dorm');
        $beta = $this->makeInstitution('Beta Mess');

        $alphaAdmin = $this->makeTenantUser($alpha, 'Institution Admin', 'admin@alpha.test', 'Alpha Admin');

        // One broadcast in each workspace, written directly.
        InstitutionBroadcast::create([
            'institution_id' => $alpha->id,
            'title' => 'Alpha notice',
            'body' => 'Only Alpha should see this.',
            'audience' => 'members',
            'severity' => 'info',
            'recipients' => 1,
            'sent_by' => $alphaAdmin->id,
        ]);
        InstitutionBroadcast::create([
            'institution_id' => $beta->id,
            'title' => 'Beta notice',
            'body' => 'Only Beta should see this.',
            'audience' => 'members',
            'severity' => 'info',
            'recipients' => 1,
            'sent_by' => null,
        ]);

        $response = $this->actingAs($alphaAdmin)->get('/settings/broadcasts');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('InstitutionAdmin/Broadcasts')
            ->where('institution.id', $alpha->id)
            ->has('history', 1)
            ->where('history.0.title', 'Alpha notice')
            ->where('stats.total_broadcasts', 1)
        );
    }
}
