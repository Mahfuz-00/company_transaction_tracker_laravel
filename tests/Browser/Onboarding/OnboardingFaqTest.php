<?php

namespace Tests\Browser\Onboarding;

use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * Point 8: Onboarding FAQ Section.
 *
 * Verifies:
 * - Comprehensive FAQ accordion exists on the Onboarding/Landing page.
 * - Answering common subscription, role, and setup questions.
 * - Accordion can be expanded and collapsed dynamically.
 */
class OnboardingFaqTest extends DuskTestCase
{
    use DuskSupport;

    public function test_faq_accordion_renders_and_toggles(): void
    {
        $this->seedRbac();

        $this->step('Guest', 'Onboarding', 'view FAQ accordion on landing page', __LINE__);

        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->waitForText('Request a demo', 20);

            $browser->script("document.querySelector('section#faq').scrollIntoView();");

            $browser->waitFor('[data-testid="onboarding-faq-accordion"]', 20)
                ->assertVisible('[data-testid="onboarding-faq-accordion"]')
                ->assertVisible('[data-testid="faq-toggle-0"]')
                // Click to expand
                ->click('[data-testid="faq-toggle-0"]')
                ->waitFor('[data-testid="faq-answer-0"]', 20)
                ->assertSeeIn('[data-testid="faq-answer-0"]', 'meal price is computed')
                // Click to collapse
                ->click('[data-testid="faq-toggle-0"]')
                ->waitUntilMissing('[data-testid="faq-answer-0"]', 20);
        });
    }
}
