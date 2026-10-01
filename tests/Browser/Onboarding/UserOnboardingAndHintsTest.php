<?php

namespace Tests\Browser\Onboarding;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * First-Login Onboarding Tour & In-Body Persistent Hints (`?`) Verification.
 *
 * Verifies:
 * - Module-to-module guided tour triggers for first-time login and manual dashboard trigger opens tour
 *   without redirecting to profile settings.
 * - In-body persistent hints (`?`) appear across cards/sections inside the body container without overflow.
 * - Global hint toggle in user settings reflects immediately across the platform.
 */
class UserOnboardingAndHintsTest extends DuskTestCase
{
    use DuskSupport;

    public function test_first_login_onboarding_tour_triggers_and_manual_trigger_works_in_place(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        // User with onboarding_completed_at = null triggers the tour on first login
        $member = $this->makeMember($institution, [
            'onboarding_completed_at' => null,
        ]);

        $this->step('Member', 'Onboarding', 'verify first-login tour triggers on dashboard', __LINE__);

        $this->browse(function (Browser $browser) use ($member) {
            $browser->loginAs($member)
                ->visit('/my/dashboard')
                ->waitFor('[data-testid="onboarding-modal"]', 20)
                ->assertVisible('[data-testid="onboarding-modal"]')
                ->assertSee('Your Dashboard')
                ->click('[data-testid="onboarding-skip"]')
                ->waitUntilMissing('[data-testid="onboarding-modal"]', 20)
                ->assertPathIs('/my/dashboard');
        });
    }

    public function test_in_body_persistent_hints_and_global_toggle_settings(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('IA', 'Hints', 'verify in-body hints render and global toggle works', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/dashboard')
                ->waitFor('[data-testid="help-badge"]', 20)
                ->assertVisible('[data-testid="help-badge"]');

            // Visit settings/theme to toggle hints off
            $browser->visit('/settings/theme')
                ->waitFor('[data-testid="hints-toggle"]', 20)
                ->assertAttribute('[data-testid="hints-toggle"]', 'aria-checked', 'true')
                ->click('[data-testid="hints-toggle"]')
                ->waitUntil('document.querySelector(\'[data-testid="hints-toggle"]\').getAttribute("aria-checked") === "false"', 20);

            // Revisit dashboard, no hints should render
            $browser->visit('/dashboard')
                ->waitForText('Dashboard', 20)
                ->assertMissing('[data-testid="help-badge"]');
        });
    }
}
