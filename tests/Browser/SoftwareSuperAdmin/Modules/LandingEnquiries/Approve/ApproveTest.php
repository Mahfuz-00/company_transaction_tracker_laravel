<?php

namespace Tests\Browser\SoftwareSuperAdmin\Modules\LandingEnquiries\Approve;

use App\Models\Institution;
use App\Models\LandingEnquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * SSA → MODULES → LANDING ENQUIRIES → APPROVE.
 *
 * Route: POST /platform/enquiries/{enquiry}/approve (`ssa.enquiries.approve`),
 * guarded by `permission:monitoring.manage`; LandingEnquiryController::approve
 * provisions the institution through InstitutionProvisioner::provision().
 *
 * UI: resources/js/Pages/SSA/Enquiries.jsx -> the card button "Approve &
 * Provision" opens ApproveModal (title "Provision institution"), whose submit is
 * the only <button type="submit">.
 */
class ApproveTest extends DuskTestCase
{
    use DuskSupport;
    use RefreshDatabase;

    public function test_ssa_approves_an_enquiry_and_provisions_a_seven_day_trial(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        $enquiry = LandingEnquiry::create([
            'name' => 'Karim Ahmed',
            'email' => 'karim@acme-food.test',
            'institution_name' => 'Acme Foods Cafeteria',
            'institution_type' => 'corporate', // guessType() maps to 'company'
            'status' => 'new',
        ]);

        $this->browse(function (Browser $browser) use ($ssa) {
            $this->step('SSA', 'LandingEnquiries', 'open approve modal', __LINE__);

            $browser->loginAs($ssa)
                ->visit('/platform/enquiries')
                ->waitForText('Acme Foods Cafeteria', 20)
                ->press('Approve & Provision')
                ->waitForText('Provision institution', 15);

            $this->step('SSA', 'LandingEnquiries', 'confirm 7-day trial default + submit', __LINE__);

            // Defaults come from the modal's useForm: provision_mode 'trial',
            // trial_days = Institution::TRIAL_DAYS (7).
            $browser->assertSee('7-Day Free Trial')
                ->click('button[type="submit"]')
                ->waitForText('provisioned on', 20);
        });

        $this->step('SSA', 'LandingEnquiries', 'assert provisioning side effects', __LINE__);

        $enquiry->refresh();
        $this->assertSame('approved', $enquiry->status);
        $this->assertSame($ssa->id, $enquiry->reviewed_by);

        $institution = Institution::find($enquiry->institution_id);
        $this->assertNotNull($institution);
        $this->assertSame('Acme Foods Cafeteria', $institution->name);
        $this->assertSame('company', $institution->type); // corporate -> company
        $this->assertSame('trial', $institution->onboarding_mode);
        $this->assertTrue($institution->isOnTrial());

        // The applicant's first Institution Admin is created, scoped to the
        // NEW institution, and flagged to change the temporary password.
        $admin = User::where('email', 'karim@acme-food.test')->first();
        $this->assertNotNull($admin);
        $this->assertSame($institution->id, $admin->institution_id);
        $this->assertTrue($admin->hasRole('Institution Admin'));
        $this->assertTrue($admin->mustChangePassword());
    }
}
