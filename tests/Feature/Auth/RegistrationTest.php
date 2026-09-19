<?php

namespace Tests\Feature\Auth;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Public signup is now TENANT-MAPPED and gated by an institution invite code.
 * These tests lock in the security rule: an account is never created without a
 * valid code, and when it is created it is bound to the right institution.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_register_with_a_valid_invite_code_and_are_mapped_to_their_institution(): void
    {
        $institution = Institution::create([
            'name' => 'North South University',
            'type' => 'university_dorm',
            'is_active' => true,
        ]);

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'invite_code' => $institution->invite_code,
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));

        // The new account belongs to the institution the code pointed at - it is
        // never orphaned.
        $user = User::where('email', 'test@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame($institution->id, $user->institution_id);
        $this->assertTrue($user->hasRole('Member'));
    }

    public function test_registration_without_a_valid_invite_code_is_rejected(): void
    {
        $response = $this->post('/register', [
            'name' => 'Orphan User',
            'email' => 'orphan@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'invite_code' => 'NOPE9999',
        ]);

        $response->assertSessionHasErrors('invite_code');
        $this->assertGuest();
        // Critically: NO user row was created - no orphaned account.
        $this->assertDatabaseMissing('users', ['email' => 'orphan@example.com']);
    }

    public function test_public_signup_cannot_self_assign_a_global_role(): void
    {
        $institution = Institution::create([
            'name' => 'Touch and Solve Ltd',
            'type' => 'company',
            'is_active' => true,
        ]);

        $this->post('/register', [
            'name' => 'Sneaky User',
            'email' => 'sneaky@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'invite_code' => $institution->invite_code,
            // A crafted escalation attempt - must be rejected by validation.
            'role' => 'Software Super Admin',
        ]);

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'sneaky@example.com']);
    }
}
