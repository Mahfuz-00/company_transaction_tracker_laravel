<?php

namespace Tests\Browser\SoftwareSuperAdmin\Modules\LandingEnquiries\List;

use App\Models\LandingEnquiry;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * SSA → MODULES → LANDING ENQUIRIES → LIST.
 *
 * Route: GET /platform/enquiries (`ssa.enquiries.index`), guarded by
 * `permission:monitoring.view` and re-asserted with isSuperAdmin() in
 * LandingEnquiryController::index.
 *
 * The public "Request a demo" form (LandingController::contact) writes a
 * LandingEnquiry row; this screen lists them, newest first, with `new` on top.
 */
class ListTest extends DuskTestCase
{
    use DuskSupport;

    public function test_ssa_sees_landing_enquiries_with_stats(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        LandingEnquiry::create([
            'name' => 'Karim Ahmed',
            'email' => 'karim@acme-food.test',
            'institution_name' => 'Acme Foods Cafeteria',
            'institution_type' => 'corporate',
            'message' => 'We run a 400-seat staff canteen.',
            'status' => 'new',
        ]);

        $this->browse(function (Browser $browser) use ($ssa) {
            $this->step('SSA', 'LandingEnquiries', 'visit /platform/enquiries', __LINE__);

            $browser->loginAs($ssa)
                ->visit('/platform/enquiries')
                ->waitForText('Landing Enquiries', 20);

            $this->step('SSA', 'LandingEnquiries', 'assert the lead is listed', __LINE__);

            // Enquiries.jsx renders the institution name, applicant and email.
            $browser->assertSee('Acme Foods Cafeteria')
                ->assertSee('Karim Ahmed')
                ->assertSee('karim@acme-food.test')
                // The tab bar shows the status counts (stats.new etc.).
                ->assertSee('New');
        });
    }

    public function test_enquiries_list_can_be_filtered_by_status(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        LandingEnquiry::create(['name' => 'New Lead', 'email' => 'new@lead.test', 'status' => 'new']);
        LandingEnquiry::create(['name' => 'Done Lead', 'email' => 'done@lead.test', 'status' => 'rejected']);

        $this->browse(function (Browser $browser) use ($ssa) {
            $this->step('SSA', 'LandingEnquiries', 'filter tab = Rejected', __LINE__);

            // Enquiries.jsx drives the filter through router.get with ?status=.
            $browser->loginAs($ssa)
                ->visit('/platform/enquiries?status=rejected')
                ->waitForText('Landing Enquiries', 20)
                ->assertSee('Done Lead')
                ->assertDontSee('New Lead');
        });
    }
}
