<?php

namespace Tests\Browser\SoftwareSuperAdmin\Modules\LandingEnquiries\Reject;

use App\Models\LandingEnquiry;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * SSA → MODULES → LANDING ENQUIRIES → REJECT.
 *
 * Route: POST /platform/enquiries/{enquiry}/reject (`ssa.enquiries.reject`),
 * guarded by `permission:monitoring.manage`.
 * LandingEnquiryController::reject sets status 'rejected' (no provisioning).
 *
 * UI: the "Reject" card button opens RejectModal with a review_notes textarea;
 * its submit is the only submit button in the modal.
 */
class RejectTest extends DuskTestCase
{
    use DuskSupport;

    public function test_ssa_rejects_an_enquiry(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        $enquiry = LandingEnquiry::create([
            'name' => 'Spam Lead',
            'email' => 'spam@example.test',
            'institution_name' => 'Not A Real Place',
            'status' => 'new',
        ]);

        $this->browse(function (Browser $browser) use ($ssa) {
            $this->step('SSA', 'LandingEnquiries', 'open reject modal and submit', __LINE__);

            $browser->loginAs($ssa)
                ->visit('/platform/enquiries')
                ->waitForText('Not A Real Place', 20)
                ->press('Reject')
                ->waitForText('Reject enquiry', 15)
                ->click('button[type="submit"]')
                ->waitForText('Enquiry rejected.', 15);
        });

        $this->step('SSA', 'LandingEnquiries', 'assert rejected with no institution', __LINE__);

        $enquiry->refresh();
        $this->assertSame('rejected', $enquiry->status);
        $this->assertNull($enquiry->institution_id);
    }
}
