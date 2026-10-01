<?php

namespace Tests\Browser\Settings;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * Point 4: Multi-Language Support (English, Bengali & Extensible).
 *
 * Verifies:
 * - Language selection option is placed inside user settings (strictly not in the top bar).
 * - Changing the language updates the user's preference in the database and dynamically switches UI translation.
 * - User-generated text stored in database remains untouched; system text translates.
 */
class MultiLanguagePreferencesTest extends DuskTestCase
{
    use DuskSupport;

    public function test_language_selection_in_settings_and_not_in_topbar(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution);

        $this->step('Member', 'Settings', 'verify language switcher is in settings not in top bar', __LINE__);

        $this->browse(function (Browser $browser) use ($member) {
            $browser->loginAs($member)
                ->visit('/dashboard')
                ->waitFor('[data-testid="fixed-top-bar"]', 20)
                // Top bar must NOT have language switcher
                ->assertMissing('[data-testid="fixed-top-bar"] [data-testid="language-switcher"]')
                // Navigate to settings/theme where language preference is placed
                ->visit('/settings/theme')
                ->waitFor('[data-testid="language-preference"]', 20)
                ->assertVisible('[data-testid="language-choice-en"]')
                ->assertVisible('[data-testid="language-choice-bn"]');
        });
    }

    public function test_changing_language_updates_preference_and_translates_ui(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['name' => 'Custom User University']);
        $member = $this->makeMember($institution, [
            'name' => 'Original User Name',
            'locale' => 'en',
        ]);

        $this->step('Member', 'Settings', 'change language to Bengali', __LINE__);

        $this->browse(function (Browser $browser) use ($member) {
            $browser->loginAs($member)
                ->visit('/settings/theme')
                ->waitFor('[data-testid="language-preference"]', 20)
                ->click('[data-testid="language-choice-bn"]')
                ->waitUntil('document.documentElement.lang === "bn"', 20)
                ->assertScript('document.documentElement.lang', 'bn');
        });

        // Verify database persistence
        $this->assertSame('bn', $member->fresh()->locale);
        // Verify user data remains intact
        $this->assertSame('Original User Name', $member->fresh()->name);
        $this->assertSame('Custom User University', $institution->fresh()->name);
    }
}
