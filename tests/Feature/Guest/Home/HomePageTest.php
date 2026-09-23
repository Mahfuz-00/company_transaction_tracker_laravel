<?php

namespace Tests\Feature\Guest\Home;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PUBLIC HOME / LANDING ROUTE (HTTP smoke test).
 *
 * Relocated from the Breeze "Example" scaffold into the Guest role folder: the
 * home route renders the public landing page for an unauthenticated visitor, so
 * it belongs to the Guest audience rather than sitting unnamed at the root of
 * tests/Feature.
 */
class HomePageTest extends TestCase
{
    // Migrate a fresh in-memory database for this test. Without it the app
    // boots against an EMPTY sqlite connection, so the home route's institution
    // lookup fails with "no such table: institutions" rather than testing the
    // route itself.
    use RefreshDatabase;

    /** An unauthenticated visitor can load the public landing page. */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
