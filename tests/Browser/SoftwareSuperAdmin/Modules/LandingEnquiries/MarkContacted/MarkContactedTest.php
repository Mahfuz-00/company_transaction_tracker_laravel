<?php

namespace Tests\Browser\SoftwareSuperAdmin\Modules\LandingEnquiries\MarkContacted;

use App\Models\LandingEnquiry;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * SSA → MODULES → LANDING ENQUIRIES → MARK CONTACTED.
 *
 * Route: POST /platform/enquiries/{enquiry}/contact (`ssa.enquiries.contact`),
 * guarded by `permission:monitoring.manage`.
 * LandingEnquiryController::markContacted flips status to 'contacted'.
 *
 * UI: the "Mark contacted" button only renders for status === 'new'
 * (Enquiries.jsx), and the flash is "Enquiry marked as contacted."
 */
class MarkContactedTest extends DuskTestCase
{
    use DuskSupport;

    public function test_ssa_marks_an_enquiry_as_contacted(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        $enquiry = LandingEnquiry::create([
            'name' => 'Nadia Rahman',
            'email' => 'nadia@uni-hall.test',
            'institution_name' => 'East Hall',
            'institution_type' => 'university',
            'status' => 'new',
        ]);

        $this->browse(function (Browser $browser) use ($ssa) {
            $this->step('SSA', 'LandingEnquiries', 'click Mark contacted', __LINE__);

            $browser->loginAs($ssa)
                ->visit('/platform/enquiries')
                ->waitForText('East Hall', 20)
                ->press('Mark contacted')
                ->waitForText('Enquiry marked as contacted.', 15);
        });

        $this->step('SSA', 'LandingEnquiries', 'assert status flipped to contacted', __LINE__);

        $this->assertSame('contacted', $enquiry->fresh()->status);
        $this->assertSame($ssa->id, $enquiry->fresh()->reviewed_by);
    }
}
