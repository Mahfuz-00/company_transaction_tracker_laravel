<?php

namespace Tests\Browser\MealManager\Login;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * MEAL MANAGER → LOGIN.
 *
 * A Meal Manager signs in through the real form and lands on the tenant-scoped
 * workspace dashboard (route `dashboard`, DashboardController::index).
 *
 * Source facts: Auth/Login.jsx -> "Welcome back", #email/#password,
 * "Sign In to Dashboard"; DashboardController sends a non-SSA, non-Member to the
 * scoped dashboard.
 */
class LoginTest extends DuskTestCase
{
    use DuskSupport;
    use RefreshDatabase;

    public function test_meal_manager_signs_in_and_lands_on_the_workspace_dashboard(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['name' => 'North South University Dorm']);
        $manager = $this->makeMealManager($institution, ['email' => 'manager@north.test']);

        $this->step('MealManager', 'Login', 'visit /login', __LINE__);

        $this->browse(function (Browser $browser) use ($manager) {
            $browser->visit('/login')
                ->waitForText('Welcome back', 20)
                ->waitFor('#email', 20);

            $this->step('MealManager', 'Login', 'submit credentials', __LINE__);

            $browser->type('#email', $manager->email)
                ->type('#password', 'password')
                ->press('Sign In to Dashboard');

            $this->step('MealManager', 'Login', 'assert /dashboard', __LINE__);

            $browser->waitForLocation('/dashboard', 20)
                ->assertPathIs('/dashboard');
        });
    }
}
