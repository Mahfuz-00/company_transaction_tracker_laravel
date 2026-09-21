<?php

namespace Tests\Browser\SoftwareSuperAdmin\Login;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * SOFTWARE SUPER ADMIN → LOGIN.
 *
 * Verifies the global platform owner signs in through the real login form and is
 * redirected to the GLOBAL platform dashboard, never a tenant workspace.
 *
 * Source facts:
 *   - Form: resources/js/Pages/Auth/Login.jsx -> heading "Welcome back",
 *     fields #email / #password, button "Sign In to Dashboard".
 *   - POST /login (route `login`) -> AuthenticatedSessionController::store.
 *   - DashboardController::index redirects an unswitched SSA to route
 *     `ssa.dashboard` -> GET /platform (MonitoringController::index).
 */
class LoginTest extends DuskTestCase
{
    use DuskSupport;
    use RefreshDatabase;

    public function test_ssa_signs_in_and_lands_on_the_global_platform_dashboard(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        $this->browse(function (Browser $browser) use ($ssa) {
            $this->step('SSA', 'Login', 'visit /login', __LINE__);

            $browser->visit('/login')
                ->waitForText('Welcome back', 20)
                ->waitFor('#email', 20);

            $this->step('SSA', 'Login', 'type credentials + submit', __LINE__);

            $browser->type('#email', $ssa->email)
                ->type('#password', 'password')
                ->press('Sign In to Dashboard');

            $this->step('SSA', 'Login', 'assert redirect to /platform', __LINE__);

            $browser->waitForLocation('/platform', 20)
                ->assertPathIs('/platform')
                // MonitoringController renders Settings/Monitoring under the SSA
                // chrome; the sidebar heading is the platform section.
                ->assertSee('Software Super Admin');
        });
    }

    public function test_ssa_with_invalid_credentials_is_rejected(): void
    {
        $this->seedRbac();
        $this->makeSuperAdmin();

        $this->step('SSA', 'Login', 'submit wrong password', __LINE__);

        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                ->waitFor('#email', 20)
                ->type('#email', 'ssa@platform.test')
                ->type('#password', 'definitely-wrong')
                ->press('Sign In to Dashboard')
                // LoginRequest throws auth.failed; Login.jsx renders it inline.
                ->waitForText('These credentials do not match our records.', 20)
                ->assertPathIs('/login');
        });
    }
}