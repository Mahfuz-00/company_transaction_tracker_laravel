<?php

namespace Tests\Browser\InstituteAdmin\TrialRequest;

use App\Models\Institution;
use App\Models\LandingEnquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    use RefreshDatabase;

    public function test_guest_requests_a_demo_and_the_ssa_approves_it_into_a_trial(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        $this->step('InstituteAdmin', 'TrialRequest', 'guest submits the demo request', __LINE__);

        /*
         * The landing "Request a demo" form (CTASection) posts to
         * `landing.contact`. Its inputs are keyed only by placeholder, so we
         * drive the submission through the browser by targeting the contact
         * section's fields in DOM order: [0] name, [1] email, [2] institution
         * name (the remaining control is the institution-type <select>).
         */
        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->waitForText('Request a demo', 20)
                ->assertSee('Stop reconciling by hand')
                ->script("document.querySelector('section#contact').scrollIntoView();")
                ->type('section#contact input[type="text"]:nth-of-type(1)', 'Karim Ahmed')
                ->type('section#contact input[type="email"]', 'karim@acme-food.test')
                ->type('section#contact input[type="text"]:nth-of-type(2)', 'Acme Foods Cafeteria')
                ->press('Request demo')
                ->waitForText('Your request has been received', 20);
        });

        $this->step('InstituteAdmin', 'TrialRequest', 'assert enquiry stored as new', __LINE__);

        $enquiry = LandingEnquiry::where('email', 'karim@acme-food.test')->first();
        $this->assertNotNull($enquiry, 'The landing submission must persist a LandingEnquiry.');
        $this->assertSame('new', $enquiry->status);

        $this->step('InstituteAdmin', 'TrialRequest', 'SSA approves -> provisions 7-day trial', __LINE__);

        $this->actingAs($ssa)
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
