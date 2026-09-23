<?php

namespace Tests\Feature\Api\Auth;

use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\ApiTestCase;

/**
 * MOBILE AUTH (Sanctum bearer tokens).
 *
 * Locks in the token contract the mobile client depends on: exchange
 * credentials for a token, read the identity back, revoke the token on logout,
 * and behave correctly for bad/inactive credentials.
 */
class ApiAuthenticationTest extends ApiTestCase
{
    public function test_login_returns_a_bearer_token_and_the_user(): void
    {
        $institution = $this->makeInstitution();
        $this->makeStaff($institution, ['email' => 'admin@north.test']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@north.test',
            'password' => 'password',
            'device_name' => 'phpunit',
        ]);

        // The exact envelope the client parses.
        $response->assertOk()->assertJsonStructure([
            'data' => [
                'token',
                'token_type',
                'user' => [
                    'id', 'name', 'email', 'phone', 'designation', 'avatar_url',
                    'status', 'institution_id', 'roles', 'permissions',
                    'is_super_admin', 'last_login_at',
                ],
            ],
        ]);

        $this->assertSame('Bearer', $response->json('data.token_type'));
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertSame($institution->id, $response->json('data.user.institution_id'));
    }

    public function test_login_is_rejected_with_a_wrong_password(): void
    {
        $institution = $this->makeInstitution();
        $this->makeStaff($institution, ['email' => 'admin@north.test']);

        // 422 (not 401) matches the documented contract for bad credentials.
        $this->postJson('/api/auth/login', [
            'email' => 'admin@north.test',
            'password' => 'wrong-password',
        ])->assertStatus(422)->assertJsonStructure(['message']);
    }

    public function test_login_is_forbidden_for_an_inactive_account(): void
    {
        $institution = $this->makeInstitution();
        $this->makeStaff($institution, ['email' => 'admin@north.test', 'status' => 'inactive']);

        $this->postJson('/api/auth/login', [
            'email' => 'admin@north.test',
            'password' => 'password',
        ])->assertStatus(403);
    }

    public function test_me_returns_the_authenticated_user(): void
    {
        $institution = $this->makeInstitution();
        $user = $this->makeStaff($institution, ['email' => 'me@north.test']);

        Sanctum::actingAs($user);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'me@north.test')
            ->assertJsonPath('data.institution_id', $institution->id);
    }

    public function test_logout_revokes_only_the_calling_token(): void
    {
        $institution = $this->makeInstitution();
        $user = $this->makeStaff($institution);

        // Two devices: only the calling one should be revoked.
        $calling = $user->createToken('phone-a');
        $other = $user->createToken('phone-b');

        $this->withHeader('Authorization', 'Bearer ' . $calling->plainTextToken)
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $calling->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $other->accessToken->id]);
    }

    public function test_registration_creates_a_member_bound_to_no_untrusted_role(): void
    {
        // The Member role must exist before register() can assign it.
        $this->seed(RolesAndPermissionsSeeder::class);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'New Mobile User',
            'email' => 'newbie@example.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'device_name' => 'phpunit',
        ]);

        $response->assertCreated();
        $this->assertNotEmpty($response->json('data.token'));

        $user = \App\Models\User::where('email', 'newbie@example.test')->firstOrFail();
        $this->assertTrue($user->hasRole('Member'));
        // A crafted global role can never be granted through signup.
        $this->assertFalse($user->hasRole('Software Super Admin'));
    }
}
