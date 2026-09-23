<?php

namespace Tests\Browser\InstituteAdmin\TrialRequest\Feature;

use App\Models\Institution;
use App\Models\LandingEnquiry;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * INSTITUTE ADMIN → TRIAL REQUEST (from the Welcome page, needs SSA approval).
 *
 * This is the END-TO-END onboarding bridge between roles:
 *
 *   1. A GUEST submits the landing "Request a demo" form -> POST /contact
 *      (`landing.contact`, LandingController::contact) which stores a
 *      LandingEnquiry with status 'new'.
 *   2. The SSA APPROVES it -> POST /platform/enquiries/{enquiry}/approve
 *      (`ssa.enquiries.approve`) which provisions the institution on a 7-day
 *      trial and creates its first Institution Admin.
 *   3. That new Institute Admin can then sign in and reach the workspace.
 *
 * It proves the "request a trial -> SSA approves -> admin gets in" chain the
 * product promises.
 */
class TrialRequestTest extends DuskTestCase
{
    use DuskSupport;

    public function test_guest_requests_a_demo_and_the_ssa_approves_it_into_a_trial(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        $this->step('InstituteAdmin', 'TrialRequest', 'guest submits the demo request', __LINE__);

        /*
         * The landing "Request a demo" form (CTASection) posts to
         * `landing.contact`. Its inputs are keyed only by placeholder, so we
         * fill them through a native value setter + input event (the
         * React-safe way), NOT via Dusk type():
         *
         *   - Browser::script() returns the raw JS result (an ARRAY here), so
         *     chaining ->type() after it throws "Call to a member function type()
         *     on array". script() must be its own standalone statement.
         *   - Dusk's type() also mis-parses a CSS attribute selector containing
         *     '[' (a querySelector string like input[type="text"]), which is the
         *     second reason the old chained selectors failed.
         *
         * Placeholders rendered by CTASection.jsx: 'Your name', 'Work email',
         * 'Institution name'. This mirrors the proven flow in WelcomeTest.
         */
        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->waitForText('Request a demo', 20)
                ->assertSee('Stop reconciling by hand');

            // Standalone: script() returns an array, breaking any method chain.
            $browser->script("document.querySelector('section#contact').scrollIntoView();");

            $browser->script("(() => {
                const set = (ph, val) => {
                    const el = [...document.querySelectorAll('input')].find(i => i.placeholder === ph);
                    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
                    setter.call(el, val);
                    el.dispatchEvent(new Event('input', { bubbles: true }));
                };
                set('Your name', 'Karim Ahmed');
                set('Work email', 'karim@acme-food.test');
                set('Institution name', 'Acme Foods Cafeteria');
            })();");

            $browser->press('Request demo')
                ->waitForText('Your request has been received', 20);
        });

        $this->step('InstituteAdmin', 'TrialRequest', 'assert enquiry stored as new', __LINE__);

        $enquiry = LandingEnquiry::where('email', 'karim@acme-food.test')->first();
        $this->assertNotNull($enquiry, 'The landing submission must persist a LandingEnquiry.');
        $this->assertSame('new', $enquiry->status);

        $this->step('InstituteAdmin', 'TrialRequest', 'SSA approves -> provisions 7-day trial', __LINE__);

        $this->httpAs($ssa)
            ->post("/platform/enquiries/{$enquiry->id}/approve", [
                'name' => 'Acme Foods Cafeteria',
                'type' => 'company',
                'provision_mode' => 'trial',
                'trial_days' => 7,
            ])
            ->assertSessionHas('success');

        $institution = Institution::find($enquiry->fresh()->institution_id);
        $this->assertNotNull($institution);
        $this->assertTrue($institution->isOnTrial());

        $this->step('InstituteAdmin', 'TrialRequest', 'the new admin can sign in', __LINE__);

        $admin = User::where('email', 'karim@acme-food.test')->first();
        $this->assertNotNull($admin);
        $this->assertTrue($admin->hasRole('Institution Admin'));

        // A provisioned admin holds a TEMPORARY password and is forced to change
        // it, so a direct login lands on the change-password screen. Prove the
        // credential works and the force-change gate fires.
        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/dashboard')
                // EnsurePasswordIsChanged redirects flagged users to password.change.
                ->waitForLocation('/password/change', 20)
                ->assertPathIs('/password/change');
        });
    }
}
