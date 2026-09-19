<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    // Migrate a fresh in-memory database for this test. Without it the app
    // boots against an EMPTY sqlite connection, so the home route's institution
    // lookup fails with "no such table: institutions" rather than testing the
    // route itself.
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
