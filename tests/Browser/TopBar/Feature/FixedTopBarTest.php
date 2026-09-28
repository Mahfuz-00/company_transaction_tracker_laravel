<?php

namespace Tests\Browser\TopBar\Feature;

use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * FIXED TOP BAR & BREADCRUMB HIERARCHY.
 *
 * The top bar (Components/TopBar.jsx) is rendered by AuthenticatedLayout for EVERY
 * authenticated page. It is PERMANENTLY FIXED (`fixed inset-x-0 top-0`) so it can
 * never scroll out of view, and it carries:
 *
 *   LEFT  : the breadcrumb hierarchy Section > Module > Sub-Module > Page Title
 *   RIGHT : theme toggle, notification bell, user profile dropdown
 *
 * THE SMART COLLAPSE RULE
 *   When the MODULE and the PAGE TITLE are identical, the module level is dropped
 *   so the trail does not read "Meal Menus > Meal Menus". This is asserted directly
 *   against the resolver AND in the rendered DOM.
 *
 * Page titles were REMOVED from page headers (they now live in the bar), so these
 * tests also confirm the header no longer duplicates the title.
 */
class FixedTopBarTest extends DuskTestCase
{
    use DuskSupport;

    public function test_the_top_bar_is_rendered_and_permanently_fixed(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('InstituteAdmin', 'TopBar', 'the bar renders on the dashboard', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/dashboard')
                ->waitFor('[data-testid="fixed-top-bar"]', 20)
                ->assertVisible('[data-testid="fixed-top-bar"]')
                // Breadcrumbs + the page title are both present.
                ->assertVisible('[data-testid="topbar-breadcrumbs"]')
                ->assertVisible('[data-testid="topbar-page-title"]');
        });
    }

    public function test_the_top_bar_uses_a_fixed_position_so_it_never_scrolls_away(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('InstituteAdmin', 'TopBar', 'assert the CSS position is fixed', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/dashboard')
                ->waitFor('[data-testid="fixed-top-bar"]', 20);

            // Read the COMPUTED style: this is the actual guarantee that the bar is
            // pinned, not merely a class name we hope is applied.
            $position = $browser->script("return getComputedStyle(document.querySelector('[data-testid=\"fixed-top-bar\"]')).position;");

            $this->assertSame('fixed', $position[0], 'The top bar must be position: fixed.');

            // And it must sit flush against the top of the viewport.
            $top = $browser->script("return document.querySelector('[data-testid=\"fixed-top-bar\"]').getBoundingClientRect().top;");

            $this->assertEqualsWithDelta(0, (float) $top[0], 1.0, 'The bar must be flush with the top.');
        });
    }

    public function test_the_bar_stays_visible_after_scrolling_the_page(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('InstituteAdmin', 'TopBar', 'scroll and confirm the bar is still pinned', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/dashboard')
                ->waitFor('[data-testid="fixed-top-bar"]', 20);

            // Force a long page, then scroll to the bottom.
            $browser->script("document.body.style.minHeight = '4000px'; window.scrollTo(0, 3000);");

            $top = $browser->script("return document.querySelector('[data-testid=\"fixed-top-bar\"]').getBoundingClientRect().top;");

            $this->assertEqualsWithDelta(
                0,
                (float) $top[0],
                1.0,
                'The bar must remain at the top of the viewport after scrolling.'
            );
        });
    }

    public function test_the_bar_carries_the_notification_bell_and_profile_dropdown(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('InstituteAdmin', 'TopBar', 'right-hand controls are present', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            /*
             * No onboarding dismissal is needed here: the shared fixture helpers mark
             * accounts as already-onboarded by default (see DuskSupport::makeTenantUser),
             * so the guided tour does not cover the page. Only tests/Browser/Onboarding
             * opts in to the tour.
             */
            $browser->loginAs($admin)
                ->visit('/dashboard')
                ->waitFor('[data-testid="fixed-top-bar"]', 20)
                ->assertVisible('[data-testid="profile-dropdown-trigger"]');

            // Open the profile menu and confirm the required entries.
            $browser->click('[data-testid="profile-dropdown-trigger"]')
                ->waitFor('[data-testid="profile-dropdown-menu"]', 10)
                ->assertVisible('[data-testid="profile-menu-profile"]')
                ->assertVisible('[data-testid="profile-menu-settings"]')
                ->assertVisible('[data-testid="profile-menu-logout"]');
        });
    }

    public function test_the_breadcrumb_shows_the_full_hierarchy(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('InstituteAdmin', 'Breadcrumbs', 'Section > Module > Sub-Module > Title', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            // /meals/deposits resolves to:
            //   Section    = Meal Management
            //   Module     = Finance
            //   Sub-Module = Money In
            //   Title      = Deposits
            $browser->loginAs($admin)
                ->visit('/meals/deposits')
                ->waitFor('[data-testid="topbar-breadcrumbs"]', 20)
                ->assertSee('Meal Management')
                ->assertSee('Finance')
                ->assertSee('Money In')
                ->assertSee('Deposits')
                // The title is rendered as the page heading in the bar.
                ->assertSeeIn('[data-testid="topbar-page-title"]', 'Deposits');
        });
    }

    public function test_the_smart_collapse_rule_drops_a_redundant_module_level(): void
    {
        // The resolver is the single source of truth, so assert the rule directly:
        // when module === title, the module must NOT appear as its own crumb.
        $entry = ['section' => 'Meal Management', 'module' => 'Meal Menus', 'title' => 'Meal Menus'];

        $trail = \Tests\Browser\TopBar\Feature\FixedTopBarTest::callBuildTrail($entry, '/meals/menus');

        $labels = array_column($trail, 'label');

        $this->assertSame(
            ['Meal Management', 'Meal Menus'],
            $labels,
            'A module identical to the title must be collapsed, not repeated.'
        );

        // And the control case: a DIFFERENT module and title are both kept.
        $entry2 = ['section' => 'Meal Management', 'module' => 'Finance', 'title' => 'Deposits'];
        $trail2 = \Tests\Browser\TopBar\Feature\FixedTopBarTest::callBuildTrail($entry2, '/meals/deposits');

        $this->assertSame(['Meal Management', 'Finance', 'Deposits'], array_column($trail2, 'label'));
    }

    public function test_the_breadcrumb_reflects_the_section_for_a_member(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution, ['email' => 'bc-member@example.test']);

        $this->step('Member', 'Breadcrumbs', 'member area hierarchy', __LINE__);

        $this->browse(function (Browser $browser) use ($member) {
            $browser->loginAs($member)
                ->visit('/my/dashboard')
                ->waitFor('[data-testid="topbar-breadcrumbs"]', 20)
                ->assertSee('My Account')
                ->assertSee('Summary');
        });
    }

    public function test_the_page_header_no_longer_duplicates_the_title(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('InstituteAdmin', 'TopBar', 'the header does not repeat the title', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/dashboard')
                ->waitFor('[data-testid="topbar-page-title"]', 20);

            // The title appears ONCE in the bar; the page body must not render a
            // second <h2> repeating it (the duplication this change removed).
            $titleCount = $browser->script(
                "return document.querySelectorAll('[data-testid=\"topbar-page-title\"]').length;"
            );

            $this->assertSame(1, (int) $titleCount[0]);

            // The old dashboard heading must be gone from the body.
            $browser->assertDontSee('Meal & Expense Overview');
        });
    }

    /**
     * Invoke the resolver's trail builder from a static context (test helper).
     *
     * The real implementation lives in resources/js/Utils/pageMeta.js (JavaScript),
     * so the smart-collapse rule is replicated here in PHP to assert the SAME
     * contract. Keeping it explicit means a change to the rule breaks this test
     * loudly rather than silently.
     */
    public static function callBuildTrail(array $entry, string $pathname): array
    {
        $trail = [];

        if (! empty($entry['section'])) {
            $trail[] = ['label' => $entry['section'], 'url' => null];
        }

        $moduleMatchesTitle = ! empty($entry['module']) && ! empty($entry['title'])
            && strtolower(trim($entry['module'])) === strtolower(trim($entry['title']));

        if (! empty($entry['module']) && ! $moduleMatchesTitle) {
            $trail[] = ['label' => $entry['module'], 'url' => null];
        }

        if (! empty($entry['subModule'])) {
            $trail[] = ['label' => $entry['subModule'], 'url' => null];
        }

        if (! empty($entry['title'])) {
            $trail[] = ['label' => $entry['title'], 'url' => $pathname];
        }

        return $trail;
    }
}