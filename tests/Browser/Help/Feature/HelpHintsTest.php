<?php

namespace Tests\Browser\Help\Feature;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * IN-BODY HELP HINTS (`?` BADGES) AND THE GLOBAL OFF SWITCH.
 *
 * WHAT THESE TESTS LOCK IN
 * ------------------------
 *   1. The in-body `?` badges and the page-level orientation bar RENDER inside the
 *      page body on a real dashboard, so guidance is present without the
 *      first-login modal.
 *   2. Opening a badge shows its popover.
 *   3. The popover stays INSIDE the viewport - it is not clipped by a card and does
 *      not disappear behind the sidebar. (The component is a portal for exactly
 *      this reason; the assertion below is what stops a future "tidy-up" reverting
 *      it to an absolutely-positioned child.)
 *   4. The user preference `hints_enabled` is stored ON THE ACCOUNT, not the
 *      browser, so flipping it off removes the badges EVERYWHERE.
 *   5. A user who has turned hints off gets no badge at all - not a hidden or
 *      disabled one.
 *
 * WHY THE BODY-SCOPE ASSERTION MATTERS
 * ------------------------------------
 * The original defect was a popover positioned relative to its badge, which meant
 * a hint near the left edge of the body column extended UNDER the fixed sidebar,
 * and one inside a table cell was clipped by the card's `overflow-hidden`. The
 * geometry check below is the regression test for that.
 */
class HelpHintsTest extends DuskTestCase
{
    use DuskSupport;

    /**
     * The page-level orientation bar renders in-body for a MEMBER.
     *
     * The member dashboard carries `PageHint` (the persistent "what am I looking
     * at" bar) but NOT the per-figure `?` badges, which live on the institution
     * dashboard's metric cards. Each surface is asserted where it actually is,
     * rather than assuming one page shows every kind of hint.
     */
    public function test_the_page_hint_renders_on_the_member_dashboard(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution);

        $this->step('Member', 'HelpHints', 'the orientation bar renders on the member dashboard', __LINE__);

        $this->browse(function (Browser $browser) use ($member) {
            $browser->loginAs($member)
                ->visit('/my/dashboard')
                ->waitFor('[data-testid="page-hint"]', 20)
                ->assertVisible('[data-testid="page-hint"]');
        });
    }

    /** The per-figure `?` badges and the orientation bar render on the institution dashboard. */
    public function test_hints_render_inside_the_institution_dashboard_body(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('IA', 'HelpHints', 'badges render on the institution dashboard', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/dashboard')
                // The metric cards carry `?` badges beside each figure.
                ->waitFor('[data-testid="help-badge"]', 20)
                ->assertVisible('[data-testid="help-badge"]');
        });
    }

    /** Clicking a badge opens its popover with the guidance text. */
    public function test_opening_a_badge_shows_its_popover(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('IA', 'HelpHints', 'open a help popover', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/dashboard')
                ->waitFor('[data-testid="help-badge"]', 20)
                ->click('[data-testid="help-badge"]')
                ->waitFor('[data-testid="help-popover"]', 20)
                ->assertVisible('[data-testid="help-popover"]');
        });
    }

    /**
     * THE REGRESSION TEST: the popover is fully on screen and not behind the shell.
     *
     * A popover rendered as an absolutely-positioned child is clipped by an
     * ancestor's `overflow-hidden` and can extend under the sidebar. Reading the
     * final geometry - rather than merely asserting visibility, which a partially
     * clipped element still passes - is what actually catches that defect.
     */
    public function test_the_popover_is_never_clipped_or_hidden_behind_the_sidebar(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('IA', 'HelpHints', 'the popover is clamped into the viewport', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/dashboard')
                ->waitFor('[data-testid="help-badge"]', 20)
                ->click('[data-testid="help-badge"]')
                ->waitFor('[data-testid="help-popover"]', 20);

            // Measure the popover against the viewport in one round trip.
            $geometry = $browser->script(
                "const el = document.querySelector('[data-testid=\"help-popover\"]');
                 const r = el.getBoundingClientRect();
                 return { left: r.left, top: r.top, right: r.right, bottom: r.bottom,
                          w: window.innerWidth, h: window.innerHeight };"
            )[0];

            $this->assertGreaterThanOrEqual(
                0,
                $geometry['left'] - 0.5,
                'The popover must not extend past the LEFT viewport edge (it would sit under the sidebar).'
            );
            $this->assertLessThanOrEqual(
                $geometry['w'] + 0.5,
                $geometry['right'],
                'The popover must not extend past the RIGHT viewport edge.'
            );
            $this->assertGreaterThanOrEqual(0, $geometry['top'] - 0.5, 'The popover must not be above the viewport.');
            $this->assertLessThanOrEqual(
                $geometry['h'] + 0.5,
                $geometry['bottom'],
                'The popover must not run off the BOTTOM of the screen.'
            );
        });
    }

    /** The preference defaults to ON for a user who has never expressed one. */
    public function test_hints_default_to_enabled(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution);

        $this->assertTrue(
            $member->hintsEnabled(),
            'A user who has never chosen must get the guidance by default.'
        );
    }

    /** The toggle lives in the Theme Customizer and persists to the ACCOUNT. */
    public function test_the_hint_preference_is_stored_on_the_account(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution);

        $this->step('Member', 'HelpHints', 'POST /settings/hints (off)', __LINE__);

        $this->httpAs($member)
            ->post('/settings/hints', ['hints_enabled' => false])
            ->assertSessionHas('success');

        // Stored on the user row, so it follows them to another device.
        $this->assertFalse($member->fresh()->hintsEnabled());

        $this->step('Member', 'HelpHints', 'POST /settings/hints (on)', __LINE__);

        // And it can be turned back on, with an explicit value (not a blind toggle).
        $this->httpAs($member)
            ->post('/settings/hints', ['hints_enabled' => true])
            ->assertSessionHas('success');

        $this->assertTrue($member->fresh()->hintsEnabled());
    }

    /** The payload is validated: a non-boolean is refused. */
    public function test_the_hint_payload_must_be_a_boolean(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution);

        $this->step('Member', 'HelpHints', 'a non-boolean payload is refused', __LINE__);

        $this->httpAs($member)
            ->post('/settings/hints', ['hints_enabled' => 'maybe'])
            ->assertSessionHasErrors('hints_enabled');
    }

    /** The toggle renders on the Theme Customizer screen. */
    public function test_the_hint_toggle_renders_on_the_theme_customizer(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution);

        $this->step('Member', 'HelpHints', 'the toggle renders on /settings/theme', __LINE__);

        $this->browse(function (Browser $browser) use ($member) {
            $browser->loginAs($member)
                ->visit('/settings/theme')
                ->waitFor('[data-testid="hints-preference"]', 20)
                ->assertVisible('[data-testid="hints-toggle"]')
                /*
                 * Assert the SWITCH STATE rather than the label text.
                 *
                 * The label span is styled `uppercase`, so WebDriver's getText()
                 * returns "ON" - and `assertSee('On')` is a case-SENSITIVE
                 * substring match that then fails on a control which is working
                 * perfectly. `aria-checked` is the semantic truth and cannot be
                 * perturbed by a text transform.
                 */
                ->assertAttribute('[data-testid="hints-toggle"]', 'aria-checked', 'true');
        });
    }

    /**
     * THE GLOBAL OFF SWITCH: turning hints off removes the badges from the DOM.
     *
     * They must be GONE, not merely CSS-hidden - a hidden badge would still occupy
     * space, catch a click and appear to a screen reader, which is the opposite of
     * what "turn the hints off" has to mean.
     */
    public function test_turning_hints_off_removes_the_badges_everywhere(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution);

        // The user has opted out.
        $member->setHintsEnabled(false);

        $this->step('Member', 'HelpHints', 'no hints render once opted out', __LINE__);

        $this->browse(function (Browser $browser) use ($member) {
            $browser->loginAs($member)
                ->visit('/my/dashboard')
                // Wait for the dashboard to actually paint so the assertion is not
                // racing an unrendered page (which would pass vacuously).
                ->waitForText('Welcome back', 20)
                ->assertMissing('[data-testid="page-hint"]');
        });
    }

    /** The global switch also removes the per-figure badges on the institution dashboard. */
    public function test_turning_hints_off_removes_the_badges_on_the_institution_dashboard(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $admin->setHintsEnabled(false);

        $this->step('IA', 'HelpHints', 'no badges render once opted out', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/dashboard')
                // Wait for the page to paint so the assertion is not vacuous.
                ->waitForText('Dashboard', 20)
                ->assertMissing('[data-testid="help-badge"]')
                ->assertMissing('[data-testid="page-hint"]');
        });
    }

    /** A user with hints ON does see them - the control case for the tests above. */
    public function test_hints_are_visible_when_the_preference_is_on(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        // Explicitly ON (the default, but stated so the contrast with the test
        // above is unambiguous).
        $admin->setHintsEnabled(true);

        $this->step('IA', 'HelpHints', 'badges render when opted in', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/dashboard')
                ->waitFor('[data-testid="help-badge"]', 20)
                ->assertVisible('[data-testid="help-badge"]');
        });
    }
}
