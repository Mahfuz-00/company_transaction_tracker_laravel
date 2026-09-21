<?php

namespace Tests\Browser\MealManager\Modules\Theme;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * MEAL MANAGER → MODULES → THEME CUSTOMIZER.
 *
 * Route: GET /settings/theme (`settings.theme.edit`) - reachable by EVERY
 * authenticated user (no permission gate). The theme follows the PERSON, stored
 * in `users.theme` (database half) and mirrored to the browser.
 */
class ThemeTest extends DuskTestCase
{
    use DuskSupport;
    use RefreshDatabase;

    public function test_meal_manager_can_personalise_the_theme(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $manager = $this->makeMealManager($institution);

        $this->step('MealManager', 'Theme', 'open Theme Customizer', __LINE__);

        $this->browse(function (Browser $browser) use ($manager) {
            $browser->loginAs($manager)
                ->visit('/settings/theme')
                ->waitForText('Theme Customizer', 20);

            $this->step('MealManager', 'Theme', 'save dark mode', __LINE__);

            $browser->script("Array.from(document.querySelectorAll('button[aria-pressed]'))
                .find(b => b.textContent.includes('Dark mode')).click();");

            $browser->waitUntil("document.documentElement.getAttribute('data-theme-mode') === 'dark'", 10)
                ->press('Save My Theme')
                ->waitForText('Theme saved. It will follow you to every device you sign in from.', 20);
        });

        $this->assertSame('dark', $manager->fresh()->themeSettings()['mode']);
    }
}
