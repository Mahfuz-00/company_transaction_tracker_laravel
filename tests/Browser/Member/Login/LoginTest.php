<?php

namespace Tests\Browser\Member\Login;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * MEMBER → LOGIN.
 *
 * A member signs in through the real form. DashboardController::index detects
 * that the user is a Member (User::isMember()) and redirects them to their OWN
 * personal dashboard (route `member.dashboard` -> GET /my/dashboard), not the
 * manager's pooled overview.
 *
 * Source facts: Auth/Login.jsx -> "Welcome back", #email/#password,
 * "Sign In to Dashboard".
 */
class LoginTest extends DuskTestCase
{
    use DuskSupport;
    use RefreshDatabase;

    public function test_member_signs_in_and_lands_on_their_personal_dashboard(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['name' => 'North South University Dorm']);
        $member = $this->makeMember($institution, ['email' => 'member@north.test']);

        $this->step('Member', 'Login', 'visit /login', __LINE__);

        $this->browse(function (Browser $browser) use ($member) {
            $browser->visit('/login')
                ->waitForText('Welcome back', 20)
                ->waitFor('#email', 20);

            $this->step('Member', 'Login', 'submit credentials', __LINE__);

            $browser->type('#email', $member->email)
                ->type('#password', 'password')
                ->press('Sign In to Dashboard');

            $this->step('Member', 'Login', 'assert redirect to /my/dashboard', __LINE__);

            $browser->waitForLocation('/my/dashboard', 20)
                ->assertPathIs('/my/dashboard');
        });
    }
}
