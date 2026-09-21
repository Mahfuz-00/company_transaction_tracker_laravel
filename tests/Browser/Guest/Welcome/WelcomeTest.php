<?php

namespace Tests\Browser\Guest\Welcome;

use App\Models\LandingEnquiry;
use App\Models\SubscriptionPlan;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * GUEST → WELCOME (LANDING PAGE).
 *
 * Route: GET / (`home`) for a guest -> LandingController::index renders the
 * `Welcome` page (a composition of Components/Landing/*).
 *
 * Guests test ONLY the public landing page. Verified here:
 *   1. the page renders its hero + CTA copy for a logged-out visitor;
 *   2. the "Request a demo" form posts to POST /contact (`landing.contact`) and
 *      persists a LandingEnquiry with status 'new';
 *   3. the pricing grid reflects the plans the SSA marked public + active
 *      (LandingController pulls SubscriptionPlan::publicPlans()).
 *
 * ASSERTION NOTES (test-side reliability):
 *   - We use waitForText() rather than assertSee() for anything that depends on
 *     React hydration, so a slow first paint cannot produce a false failure.
 *   - The contact form's inputs have no ids, so they are targeted by their real
 *     `placeholder` attributes (stable, and read from CTASection.jsx) instead of
 *     fragile nth-of-type selectors.
 */
class WelcomeTest extends DuskTestCase
{
    use DuskSupport;

    public function test_guest_sees_the_landing_page(): void
    {
        $this->seedRbac();

        $this->step('Guest', 'Welcome', 'visit /', __LINE__);

        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                // The CTA section heading is the last section to hydrate.
                ->waitForText('Request a demo', 20)
                // Hero headline (HeroSection.jsx) + primary CTA button.
                ->waitForText('One platform for every', 20)
                ->waitForText('Create Free Account', 20);
        });
    }

    public function test_guest_submits_a_demo_request_that_becomes_an_enquiry(): void
    {
        $this->seedRbac();

        $this->step('Guest', 'Welcome', 'fill and submit the demo form', __LINE__);

        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->waitForText('Request a demo', 20);

            // Scroll the contact form into view. Run as a standalone statement:
            // Dusk's script() returns the JS result, which would break a chain.
            $browser->script("document.querySelector('section#contact').scrollIntoView();");

            $browser
                /*
                 * The contact inputs have no ids/names (CTASection.jsx renders
                 * them by placeholder only), and Dusk's type() mis-parses a CSS
                 * attribute selector containing '[' as an array argument. So we
                 * set the React-controlled inputs through the native value setter
                 * + an input event - the standard React-safe way to fill a form
                 * Dusk cannot address by id.
                 */
                ->script("(() => {
                    const set = (ph, val) => {
                        const el = [...document.querySelectorAll('input')].find(i => i.placeholder === ph);
                        const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
                        setter.call(el, val);
                        el.dispatchEvent(new Event('input', { bubbles: true }));
                    };
                    set('Your name', 'Guest Visitor');
                    set('Work email', 'guest@lead.test');
                    set('Institution name', 'Guest Corporate Kitchen');
                })();");

            $browser
                ->press('Request demo')
                // LandingController::contact flashes this exact string.
                ->waitForText('Your request has been received', 20);
        });

        $this->step('Guest', 'Welcome', 'assert a LandingEnquiry was recorded', __LINE__);

        $enquiry = LandingEnquiry::where('email', 'guest@lead.test')->first();
        $this->assertNotNull($enquiry, 'The demo form must persist a LandingEnquiry.');
        $this->assertSame('new', $enquiry->status);
        $this->assertSame('Guest Visitor', $enquiry->name);
    }

    public function test_guest_sees_public_pricing_tiers(): void
    {
        $this->seedRbac();

        // A plan the SSA has marked public + active is shown on the landing page.
        SubscriptionPlan::create([
            'key' => 'starter',
            'name' => 'Starter',
            'description' => 'For small halls',
            'monthly_price' => 1000,
            'is_free' => false,
            'member_limit' => 50,
            'manager_limit' => 3,
            'is_active' => true,
            'is_public' => true,
        ]);

        // A non-public plan must NOT appear.
        SubscriptionPlan::create([
            'key' => 'internal',
            'name' => 'Internal Only',
            'monthly_price' => 9999,
            'is_free' => false,
            'member_limit' => -1,
            'manager_limit' => -1,
            'is_active' => true,
            'is_public' => false,
        ]);

        $this->step('Guest', 'Welcome', 'assert public pricing grid', __LINE__);

        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->waitForText('Starter', 20)
                ->assertDontSee('Internal Only');
        });
    }

    public function test_a_signed_in_user_is_redirected_away_from_the_landing_page(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('Guest', 'Welcome', 'authenticated visit / redirects to dashboard', __LINE__);

        // Route `home` sends a signed-in user to route('dashboard').
        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/')
                ->waitForLocation('/dashboard', 20)
                ->assertPathIs('/dashboard');
        });
    }
}
