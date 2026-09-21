<?php

namespace Tests\Browser\InstituteAdmin\Login;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * INSTITUTE ADMIN → LOGIN.
 *
 * A tenant-bound administrator signs in through the real form and lands on the
 * SCOPED workspace dashboard (route `dashboard`, DashboardController::index),
 * NOT the SSA platform view.
 *
 * Source facts:
 *   - form: Auth/Login.jsx -> "Welcome back", #email/#password,
 *     "Sign In to Dashboard";
 *   - DashboardController::index: a non-SSA who is not a Member falls through to
 *     the tenant dashboard (role:Institution Admin).
 */
class LoginTest extends DuskTestCase
{
    use DuskSupport;
    use RefreshDatabase;

    public function test_institute_admin_signs_in_and_lands_on_the_workspace_dashboard(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['name' => 'North South University Dorm']);
        $admin = $this->makeInstitutionAdmin($institution, ['email' => 'admin@north.test']);

        $this->step('InstituteAdmin', 'Login', 'visit /login', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->visit('/login')
                ->waitForText('Welcome back', 20)
                ->waitFor('#email', 20);

            $this->step('InstituteAdmin', 'Login', 'submit credentials', __LINE__);

            $browser->type('#email', $admin->email)
                ->type('#password', 'password')
                ->press('Sign In to Dashboard');

            $this->step('InstituteAdmin', 'Login', 'assert scoped /dashboard', __LINE__);

            $browser->waitForLocation('/dashboard', 20)
                ->assertPathIs('/dashboard');
        });
    }
}
