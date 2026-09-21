<?php

namespace Tests\Browser\InstituteAdmin\Modules\Theme;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * INSTITUTE ADMIN → MODULES → THEME CUSTOMIZER.
 *
 * Routes: GET /settings/theme (`settings.theme.edit`) and PUT /settings/theme
 * (`settings.theme.update`). Deliberately NOT permission-gated - personalising
 * one's own view is a personal preference. ThemeController writes the tokens to
 * the USER's `users.theme` column (the database half of dual persistence; the
 * localStorage half is client-side).
 *
 * UI: resources/js/Pages/Settings/ThemeCustomizer.jsx -> heading
 * "Theme Customizer", "Save My Theme" / "Reset to default".
 */
class ThemeTest extends DuskTestCase
{
    use DuskSupport;

    public function test_institute_admin_saves_a_dark_theme(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution, ['email' => 'admin@north.test']);

        $this->step('InstituteAdmin', 'Theme', 'open Theme Customizer', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/settings/theme')
                ->waitForText('Theme Customizer', 20);

            $this->step('InstituteAdmin', 'Theme', 'select Dark mode and save', __LINE__);

            // The ModeCards are <button aria-pressed> containing "Light"/"Dark mode".
            $browser->script("Array.from(document.querySelectorAll('button[aria-pressed]'))
                .find(b => b.textContent.includes('Dark mode')).click();");

            $browser->waitUntil("document.documentElement.getAttribute('data-theme-mode') === 'dark'", 10)
                ->press('Save My Theme')
                // Wait for the success flash so the redirect has fully settled
                // before we touch the DOM again (querying mid-navigation can throw
                // "no such element: body html").
                ->waitForText('Theme saved. It will follow you to every device you sign in from.', 20)
                ->pause(500)
                ->waitUntil("document.documentElement.getAttribute('data-theme-mode') === 'dark'", 10);
        });

        $this->step('InstituteAdmin', 'Theme', 'assert users.theme.mode persisted', __LINE__);

        $this->assertSame('dark', $admin->fresh()->themeSettings()['mode']);
    }

    public function test_institute_admin_resets_the_theme_to_default(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution, [
            'theme' => ['mode' => 'dark', 'accent' => 'rose', 'radius' => 'xl', 'density' => 'compact', 'font' => 'poppins'],
        ]);

        $this->step('InstituteAdmin', 'Theme', 'press Reset to default', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/settings/theme')
                ->waitForText('Theme Customizer', 20)
                ->press('Reset to default')
                ->waitForText('Theme reset to the platform default.', 20);
        });

        // ThemeController::reset nulls the column; the model merges DEFAULT_THEME.
        $this->assertNull($admin->fresh()->theme);
        $this->assertSame('light', $admin->fresh()->themeSettings()['mode']);
    }
}
