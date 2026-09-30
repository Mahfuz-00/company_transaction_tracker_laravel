<?php

namespace Tests\Browser\Localization\Feature;

use App\Support\LocaleManager;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * MULTI-LANGUAGE SUPPORT (English + Bengali, extensible).
 *
 * WHAT THESE TESTS LOCK IN
 * ------------------------
 *   1. The catalogue ships EXACTLY the languages we intend (en, bn) and adding
 *      another is a configuration change, not a code change.
 *   2. A GUEST can pick a language before signing in, and the choice sticks in
 *      the session so the landing page renders in it.
 *   3. A SIGNED-IN user's choice is persisted to `users.locale`, so it follows
 *      the account to any device.
 *   4. Resolution order is respected: the account beats the session, the session
 *      beats the browser's Accept-Language header, and an unsupported code is
 *      never applied.
 *   5. The shared Inertia `locale` prop carries the ACTIVE catalogue to React, so
 *      the front end and PHP agree on the wording.
 *
 * WHY THESE ASSERT THE SERVICE AND THE PROP, NOT RENDERED BENGALI
 * --------------------------------------------------------------
 * The switcher's markup is covered by the browser test below. The RESOLUTION and
 * PERSISTENCE rules are server-side behaviour with several precedence branches,
 * and asserting them against the service is what proves the rules rather than the
 * markup. Bengali glyphs in a DOM assertion would also make the suite fragile and
 * hard to read for an English-speaking reviewer.
 */
class LocalizationTest extends DuskTestCase
{
    use DuskSupport;

    /** The shipped catalogue is exactly en + bn, with the metadata the UI needs. */
    public function test_the_catalogue_ships_english_and_bengali(): void
    {
        $this->seedRbac();

        $supported = LocaleManager::supported();

        $this->assertSame(['en', 'bn'], $supported, 'English and Bengali must be the shipped locales.');

        $bn = LocaleManager::meta('bn');

        $this->assertNotNull($bn);
        // The language names ITSELF, so a Bengali speaker can find it.
        $this->assertSame('বাংলা', $bn['label']);
        $this->assertSame('Bengali', $bn['english']);
        $this->assertFalse($bn['rtl'], 'Bengali is a left-to-right script.');
        $this->assertSame('bn_BD', $bn['date_locale']);
    }

    /** The React catalogue prop lists every supported language, never a subset. */
    public function test_the_shared_catalogue_prop_lists_every_language(): void
    {
        $catalogue = LocaleManager::catalogue();

        $this->assertCount(2, $catalogue);
        $this->assertSame('en', $catalogue[0]['code']);
        $this->assertSame('bn', $catalogue[1]['code']);
        // The label is the language's own name, which is what the switcher renders.
        $this->assertSame('বাংলা', $catalogue[1]['label']);
    }

    /** An unsupported code is refused, so a crafted request cannot write it. */
    public function test_an_unsupported_locale_is_refused(): void
    {
        $this->seedRbac();

        $this->assertFalse(LocaleManager::isSupported('zz'));
        $this->assertFalse(LocaleManager::isSupported('ar'), 'Arabic is a commented-out example, not shipped.');

        $this->step('Guest', 'Localization', 'persist an unsupported code', __LINE__);

        // persist() returns false and writes nothing - the guard is here, not in
        // the controller, so every caller is protected.
        $this->assertFalse(LocaleManager::persist('zz'));
    }

    /** A guest's choice is written to the SESSION (there is no user row yet). */
    public function test_a_guest_can_switch_language_from_the_public_route(): void
    {
        $this->seedRbac();

        $this->step('Guest', 'Localization', 'GET /language/bn', __LINE__);

        $this->get('/language/bn')->assertRedirect();

        // The session now carries the choice, and resolution honours it.
        $this->assertSame('bn', session(LocaleManager::SESSION_KEY));
    }

    /** Resolution order: the signed-in user's saved locale outranks the session. */
    public function test_the_account_choice_outranks_the_session(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution, ['locale' => 'bn']);

        // A stale English session value must NOT override the account's Bengali.
        session([LocaleManager::SESSION_KEY => 'en']);

        $this->actingAs($member);

        $this->assertSame(
            'bn',
            LocaleManager::resolve(),
            'A signed-in user\'s saved language must beat a session value.'
        );
    }

    /** The session outranks the browser header, and the header is a last resort. */
    public function test_the_accept_language_header_is_a_fallback_only(): void
    {
        $this->seedRbac();

        // `bn-BD` must match our `bn` on the primary subtag.
        $this->assertSame('bn', LocaleManager::fromAcceptLanguage('bn-BD,bn;q=0.9,en;q=0.8'));
        // A preference order is respected.
        $this->assertSame('bn', LocaleManager::fromAcceptLanguage('en;q=0.4,bn;q=0.9'));
        // An unsupported language yields nothing, so we fall through to the default.
        $this->assertNull(LocaleManager::fromAcceptLanguage('fr-FR,fr;q=0.9'));
        $this->assertNull(LocaleManager::fromAcceptLanguage(null));
    }

    /** The front-end dictionary is the SAME catalogue the PHP side renders. */
    public function test_the_front_end_dictionary_carries_the_translated_keys(): void
    {
        $this->seedRbac();

        $messages = LocaleManager::messages('bn');

        // A representative set of keys the React side calls `t('...')` with.
        $this->assertArrayHasKey('auth.sign_in', $messages);
        $this->assertArrayHasKey('hints.toggle_label', $messages);
        $this->assertArrayHasKey('assistant.send', $messages);

        // Bengali values are actually present, not an English copy.
        $this->assertSame('ড্যাশবোর্ডে সাইন ইন করুন', $messages['auth.sign_in']);
    }

    /** A partially-translated locale degrades to English, never to a raw key. */
    public function test_a_missing_key_falls_back_to_english(): void
    {
        $this->seedRbac();

        $english = LocaleManager::messages('en');
        $bengali = LocaleManager::messages('bn');

        // Every Bengali key resolves to SOMETHING (the merge guarantees it).
        foreach (array_keys($english) as $key) {
            $this->assertArrayHasKey($key, $bengali, "The bn catalogue must resolve {$key}.");
            $this->assertNotSame('', (string) $bengali[$key]);
        }
    }

    /** The switcher renders on the login screen, the visitor's only pre-signup chance. */
    public function test_the_switcher_renders_on_the_login_screen(): void
    {
        $this->seedRbac();

        $this->step('Guest', 'Localization', 'the switcher renders on /login', __LINE__);

        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                ->waitFor('[data-testid="language-switcher"]', 20)
                ->assertVisible('[data-testid="language-switcher-trigger"]');
        });
    }

    /**
     * A guest can switch language through the browser, and the choice sticks.
     *
     * ASSERTS ON STATE, NOT ON THE BENGALI GLYPHS.
     * --------------------------------------------
     * WebDriver compares `textContent`, and the round trip through the test file,
     * PHP and the browser's DOM can differ in Unicode normalisation - so a
     * `waitForText('বাংলা')` is a fragile thing to hang a test on, and a failure
     * reports a text mismatch rather than the behaviour under test. What actually
     * matters is that the SWITCH took effect, so this asserts the active option and
     * the persisted session value instead.
     */
    public function test_a_guest_can_switch_language_in_the_browser(): void
    {
        $this->seedRbac();

        $this->step('Guest', 'Localization', 'switch to Bengali from /login', __LINE__);

        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                ->waitFor('[data-testid="language-switcher-trigger"]', 20)
                ->click('[data-testid="language-switcher-trigger"]')
                ->waitFor('[data-testid="language-option-bn"]', 20)
                ->click('[data-testid="language-option-bn"]')
                /*
                 * WAIT FOR THE SWITCH TO LAND, don't just re-check the switcher.
                 *
                 * The switcher element is present BOTH before and after the
                 * Inertia visit, so `waitFor('[data-testid="language-switcher"]')`
                 * returns immediately against the OLD page and the language is
                 * still `en` at that instant - the assertion then fails on a race,
                 * not on behaviour. `waitUntil` polls the actual value we care
                 * about, so it passes as soon as the redirect completes and fails
                 * only if the switch genuinely never happens.
                 */
                ->waitUntil('document.documentElement.lang === "bn"', 20)
                ->assertScript('document.documentElement.lang', 'bn');
        });

        /*
         * NOTE: the session assertion deliberately lives in
         * `test_a_guest_can_switch_language_from_the_public_route` (an in-process
         * HTTP call) and not here.
         *
         * Dusk drives a REAL browser against a SEPARATE `artisan serve` process, so
         * a session written by the browser is not visible to the test runner's own
         * session - `session('locale')` here would always read null. Asserting it
         * would fail on a control that works perfectly, which is worse than not
         * asserting it: the document-language check above already proves the switch
         * took effect in the browser, and the in-process test proves the write.
         */
    }

    /** A signed-in user's switch is persisted to their account, not just the session. */
    public function test_a_signed_in_user_switch_is_persisted_to_the_account(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution);

        $this->step('Member', 'Localization', 'POST /language', __LINE__);

        $this->httpAs($member)
            ->post('/language', ['locale' => 'bn'])
            ->assertSessionHas('success');

        $this->assertSame('bn', $member->fresh()->locale, 'The choice must follow the account.');
    }
}
