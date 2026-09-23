<?php

namespace Tests\Browser\SoftwareSuperAdmin\Login\Feature;

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
                // Let React commit the controlled inputs to its form state before
                // submitting (requestSubmit reads useForm's `data`).
                ->pause(500);

            // Submit through the DOM so React's onSubmit (and therefore Inertia's
            // client-side post) runs. A bare WebDriver click on this button does
            // not fire the form's `submit` event in the headless setup, which
            // leaves the page inert.
            $browser->script("document.querySelector('form').requestSubmit();");

            $this->step('SSA', 'Login', 'assert redirect to /platform', __LINE__);

            $browser->waitForLocation('/platform', 20)
                ->assertPathIs('/platform');

            // The SSA heading is rendered with CSS `uppercase`, so compare
            // case-insensitively against the visible "SOFTWARE SUPER ADMIN".
            $this->waitForTextCaseInsensitive($browser, 'Software Super Admin', 20);
        });
    }

    public function test_ssa_with_invalid_credentials_is_rejected(): void
    {
        $this->seedRbac();
        $this->makeSuperAdmin();

        $this->step('SSA', 'Login', 'submit wrong password', __LINE__);

        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                ->waitForText('Welcome back', 20)
                ->waitFor('#email', 20);

            // Fill the React-controlled inputs through the native value setter so
            // useForm's `data` is definitely populated before we submit. A plain
            // Dusk type() can leave the controlled state empty here, which makes
            // the request fail with "The email field is required." instead of the
            // credential error we are asserting.
            $browser->script("(() => {
                const set = (sel, val) => {
                    const el = document.querySelector(sel);
                    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
                    setter.call(el, val);
                    el.dispatchEvent(new Event('input', { bubbles: true }));
                };
                set('#email', 'ssa@platform.test');
                set('#password', 'definitely-wrong');
            })();");

            // Run React's onSubmit so the failure is delivered through Inertia as
            // page props (errors.email), which the login form renders inline.
            $browser->script("document.querySelector('form').requestSubmit();");

            $browser->waitUntil(
                "document.body.innerText.includes('These credentials do not match our records.')",
                20
            )->assertPathIs('/login');
        });
    }
}
