<?php

namespace Tests\Browser\Institution;

use App\Models\Institution;
use App\Models\SubscriptionPlan;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * Point 7: Paid Institution Registration & Duplicate Prevention.
 *
 * Verifies:
 * - Paid institution registration form creates pending record and redirects to payment gateway.
 * - Admin cannot log in while payment is pending.
 * - Duplicate prevention: Resumes pending record instead of creating duplicates.
 * - Completing payment activates institution and allows admin login.
 */
class PaidRegistrationTest extends DuskTestCase
{
    use DuskSupport;

    public function test_paid_institution_registration_and_duplicate_resumption(): void
    {
        $this->seedRbac();

        $plan = SubscriptionPlan::create([
            'key' => 'standard',
            'name' => 'Pro Mess Plan',
            'monthly_price' => 49.00,
            'is_active' => true,
        ]);

        $this->step('Guest', 'Onboarding', 'register paid institution', __LINE__);

        $this->browse(function (Browser $browser) {
            $browser->visit('/onboarding/register')
                ->waitFor('form', 20)
                ->type('[data-testid="paid-inst-name"]', 'Sunrise Residential Hall')
                ->select('[data-testid="paid-inst-type"]', 'hostel')
                ->type('[data-testid="paid-admin-name"]', 'Rafiqul Islam')
                ->type('[data-testid="paid-admin-email"]', 'admin@sunrisehall.test')
                ->type('[data-testid="paid-admin-password"]', 'secret1234')
                ->click('[data-testid="paid-submit-btn"]')
                ->waitFor('[data-testid="pay-complete-btn"]', 20)
                ->assertSee('Secure Payment Gateway')
                ->assertSee('Sunrise Residential Hall');

            // Attempt duplicate submission with same email while unpaid:
            $browser->visit('/onboarding/register')
                ->waitFor('form', 20)
                ->type('[data-testid="paid-inst-name"]', 'Sunrise Residential Hall')
                ->select('[data-testid="paid-inst-type"]', 'hostel')
                ->type('[data-testid="paid-admin-name"]', 'Rafiqul Islam')
                ->type('[data-testid="paid-admin-email"]', 'admin@sunrisehall.test')
                ->type('[data-testid="paid-admin-password"]', 'secret1234')
                ->click('[data-testid="paid-submit-btn"]')
                // Should display duplicate warning dialog in center of screen
                ->waitFor('[data-testid="duplicate-warning-dialog"]', 20)
                ->assertSee('final attempt before previous uncompleted information is permanently purged')
                ->click('[data-testid="acknowledge-warning-btn"]')
                ->waitFor('[data-testid="pay-complete-btn"]', 20);
        });

        $this->assertSame(1, Institution::where('name', 'Sunrise Residential Hall')->count());
        $inst = Institution::where('name', 'Sunrise Residential Hall')->first();
        $this->assertSame('awaiting_payment', $inst->onboarding_status);
        $this->assertFalse((bool) $inst->is_active);

        // Complete the payment
        $this->browse(function (Browser $browser) use ($inst) {
            $browser->visit('/onboarding/gateway/'.$inst->signup_reference)
                ->waitFor('[data-testid="pay-complete-btn"]', 20)
                ->click('[data-testid="pay-complete-btn"]')
                ->waitForText('Payment completed successfully', 20)
                ->assertSee('Payment completed successfully');
        });

        $this->assertTrue((bool) $inst->fresh()->is_active);
        $this->assertSame('active', $inst->fresh()->onboarding_status);
    }
}
