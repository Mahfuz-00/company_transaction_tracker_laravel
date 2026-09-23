<?php

namespace Tests\Browser\Member\ThemeCustomizer\Feature;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * MEMBER → MODULES → THEME CUSTOMIZER.
 *
 * Route: GET /settings/theme (`settings.theme.edit`) + PUT
 * (`settings.theme.update`). Deliberately NOT permission-gated: every
 * authenticated user - member included - personalises their own view. The choice
 * is persisted to `users.theme` (ThemeController::update) AND applied live to the
 * document root.
 */
class ThemeCustomizerTest extends DuskTestCase
{
    use DuskSupport;

    public function test_member_toggles_dark_mode_and_it_persists(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution, ['email' => 'theme@north.test']);

        $this->step('Member', 'ThemeCustomizer', 'open the customizer', __LINE__);

        $this->browse(function (Browser $browser) use ($member) {
            $browser->loginAs($member)
                ->visit('/settings/theme')
                ->waitForText('Theme Customizer', 20);

            $this->step('Member', 'ThemeCustomizer', 'click Dark mode and save', __LINE__);

            $browser->script("Array.from(document.querySelectorAll('button[aria-pressed]'))
                .find(b => b.textContent.includes('Dark mode')).click();");

            $browser->waitUntil("document.documentElement.getAttribute('data-theme-mode') === 'dark'", 10)
                ->press('Save My Theme')
                // Wait for the flash so the redirect settles before touching the DOM.
                ->waitForText('Theme saved. It will follow you to every device you sign in from.', 20)
                ->pause(500)
                ->waitUntil("document.documentElement.getAttribute('data-theme-mode') === 'dark'", 10);
        });

        $this->step('Member', 'ThemeCustomizer', 'assert users.theme persisted', __LINE__);

        $this->assertSame('dark', $member->fresh()->themeSettings()['mode']);
    }

    public function test_member_resets_the_theme_to_the_default(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution, [
            'email' => 'reset@north.test',
            'theme' => ['mode' => 'dark', 'accent' => 'rose', 'radius' => 'xl', 'density' => 'compact', 'font' => 'poppins'],
        ]);

        $this->step('Member', 'ThemeCustomizer', 'press Reset to default', __LINE__);

        $this->browse(function (Browser $browser) use ($member) {
            $browser->loginAs($member)
                ->visit('/settings/theme')
                ->waitForText('Theme Customizer', 20)
                ->press('Reset to default')
                ->waitForText('Theme reset to the platform default.', 20);
        });

        $this->assertNull($member->fresh()->theme);
        $this->assertSame('light', $member->fresh()->themeSettings()['mode']);
    }
}
