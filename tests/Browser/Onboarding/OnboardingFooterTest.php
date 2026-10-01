<?php

namespace Tests\Browser\Onboarding;

use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * Point 9: Onboarding Footer Section.
 *
 * Verifies:
 * - Professional footer section exists on the Onboarding/Landing page.
 * - Contains links, copyright notices, terms, and support info.
 */
class OnboardingFooterTest extends DuskTestCase
{
    use DuskSupport;

    public function test_landing_page_footer_renders_links_and_info(): void
    {
        $this->seedRbac();

        $this->step('Guest', 'Onboarding', 'verify footer on landing page', __LINE__);

        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->waitFor('[data-testid="onboarding-footer"]', 20)
                ->assertVisible('[data-testid="onboarding-footer"]')
                ->assertSee('All rights reserved')
                ->assertSee('Terms of Service')
                ->assertSee('Privacy Policy')
                ->assertSee('Register Institution')
                ->assertSee('support@nomnomytics.app');
        });
    }
}
