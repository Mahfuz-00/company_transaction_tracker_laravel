<?php

namespace Tests\Browser\Guest\Welcome;

use App\Models\Institution;
use App\Models\LandingEnquiry;
use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
 */
class WelcomeTest extends DuskTestCase
{
    use DuskSupport;
    use RefreshDatabase;

    public function test_guest_sees_the_landing_page(): void
    {
        $this->seedRbac();

        $this->step('Guest', 'Welcome', 'visit /', __LINE__);

        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->waitForText('Request a demo', 20)
                // LandingHeader brand + hero headline from HeroSection.jsx.
                ->assertSee('One platform for every')
                ->assertSee('Create Free Account');
        });
    }

    public function test_guest_submits_a_demo_request_that_becomes_an_enquiry(): void
    {
        $this->seedRbac();

        $this->step('Guest', 'Welcome', 'fill and submit the demo form', __LINE__);

        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->waitForText('Request a demo', 20)
                ->script("document.querySelector('section#contact').scrollIntoView();");

            // The contact section is the only place these fields exist; target its
            // inputs in DOM order: [0] name, [1] email, [2] institution name.
            $browser->type('section#contact input[type="text"]:nth-of-type(1)', 'Guest Visitor')
                ->type('section#contact input[type="email"]', 'guest@lead.test')
                ->type('section#contact input[type="text"]:nth-of-type(2)', 'Guest Corporate Kitchen')
                ->press('Request demo')
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
                ->assertSee('Starter')
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
