<?php

namespace Tests\Feature\Api\Auth;

use Tests\Feature\Api\ApiTestCase;

/**
 * BRUTE-FORCE PROTECTION for the mobile login endpoint.
 *
 * The API previously had NO throttle, so /api/auth/login could be hammered.
 * AppServiceProvider now defines an 'api-login' limiter (5/min per email+IP);
 * this proves the 6th rapid attempt is refused with 429.
 */
class ApiLoginThrottleTest extends ApiTestCase
{
    public function test_login_is_throttled_after_five_attempts(): void
    {
        $payload = ['email' => 'bruteforce@example.test', 'password' => 'wrong-password'];

        // The first five attempts are processed normally: they fail validation
        // with 422 (bad credentials), NOT 429.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/login', $payload)->assertStatus(422);
        }

        // The sixth trips the limiter.
        $this->postJson('/api/auth/login', $payload)->assertStatus(429);
    }
}
