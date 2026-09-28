<?php

namespace Tests\Browser\Support;

use App\Models\Institution;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Spatie\Permission\Models\Role;

/**
 * SHARED SUPPORT FOR THE ROLE-BASED DUSK SUITE.
 *
 * Every helper here mirrors the EXACT schema, roles and relationships found in
 * this codebase - nothing is invented:
 *
 *   - Roles (database/seeders/RolesAndPermissionsSeeder.php): exactly four core
 *     roles -> 'Software Super Admin' (global), 'Institution Admin',
 *     'Meal Manager', 'Member'.
 *   - Tenancy (users.institution_id): a Software Super Admin has institution_id
 *     = NULL and holds ONLY the global role; every other account is hard-bound
 *     to an institution.
 *   - Console progress logging: every test step calls step() so a developer can
 *     follow exactly what the browser is doing.
 */
trait DuskSupport
{
    /**
     * Seed the RBAC backbone. Required BEFORE any user is created, because the
     * Spatie `role:` / `permission:` route middleware and the app guards
     * (User::isSuperAdmin / isInstitutionAdmin) depend on these rows existing.
     *
     * RefreshDatabase wipes them per test, so this runs on every test.
     */
    protected function seedRbac(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * Emit a clearly-labelled progress line to the console so the run is
     * followable. Format requested:
     *
     *   ==== STEP: [Role] → [Module] → [Action] | Line ~XX ====
     *
     * `$line` is passed explicitly by the caller (the line number of the test
     * method / assertion) so the developer can jump straight to it.
     */
    protected function step(string $role, string $module, string $action, int $line = 0): void
    {
        dump("==== STEP: [{$role}] → [{$module}] → [{$action}] | Line ~{$line} ====");
    }

    /**
     * Wait for text, IGNORING CASE, and matching across a React page that may
     * apply CSS `uppercase`.
     *
     * WHY THIS EXISTS
     * ---------------
     * WebDriver's getText() returns the VISUALLY TRANSFORMED text, so an element
     * styled with Tailwind's `uppercase` yields "SOFTWARE SUPER ADMIN" even though
     * the DOM says "Software Super Admin". A literal waitForText('Software Super
     * Admin') therefore times out. Waiting case-insensitively makes the assertion
     * robust to CSS text-transform without weakening what is being checked.
     */
    protected function waitForTextCaseInsensitive(Browser $browser, string $text, int $seconds = 20): void
    {
        $browser->waitForText($text, $seconds, true);
    }

    /**
     * An authenticated HTTP test client for server-side assertions.
     *
     * WHY THIS EXISTS
     * ---------------
     * Dusk tests drive a browser, but many also assert SERVER-side effects
     * directly (a flash message, a 403, a DB write). A raw
     * `$this->httpAs($user)->post(...)` runs through the full HTTP kernel and
     * is rejected by CSRF (HTTP 419). Disabling ONLY VerifyCsrfToken (not auth,
     * not role/permission) keeps every security assertion intact while letting
     * the request reach the controller.
     *
     * Usage:  $this->httpAs($admin)->post('/meals/departments', [...])->assertSessionHas('success');
     */
    protected function httpAs($user)
    {
        /*
         * Laravel 11/12 replaced VerifyCsrfToken with ValidateCsrfToken, and
         * withoutMiddleware() matches by EXACT class name. Disabling both covers
         * either version so a test-side POST never trips a 419.
         */
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
        ]);

        /*
         * Set a same-origin Referer so a controller's back() redirect resolves
         * to a real app page (not an empty referer -> "/" -> /dashboard chain).
         * Without it, a redirect CHAIN can drop the flashed session data before
         * assertSessionHas() inspects the final response.
         */
        $this->withHeader('Referer', config('app.url') . '/dashboard');

        // NOTE: must call actingAs(), NOT httpAs() - a self-call here would
        // recurse infinitely and exhaust memory.
        return $this->actingAs($user);
    }

    /**
     * An UNauthenticated HTTP client for routes behind the `guest` middleware.
     *
     * WHY THIS EXISTS (and is separate from httpAs())
     * ------------------------------------------------
     * `httpAs()` authenticates via actingAs(). That is correct for an
     * authenticated route, but it BREAKS any route behind `guest`:
     *
     *     POST /register        -> redirect to /dashboard, controller never runs
     *     POST /register/validate-invite-code
     *
     * The symptom is confusing rather than obvious: the test sees a 302 with no
     * validation errors and no created row, which reads like "registration is
     * broken" when in fact the request was bounced by middleware before it
     * arrived.
     *
     * This helper therefore disables CSRF (so a test-side POST is not a 419) but
     * deliberately does NOT call actingAs(), leaving the request a genuine guest -
     * exactly the state the public signup form is in.
     *
     * Usage:  $this->httpAsGuest()->post('/register', [...])->assertSessionHasErrors('invite_code');
     */
    protected function httpAsGuest()
    {
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
        ]);

        // A same-origin referer so back() resolves to a real page rather than
        // bouncing through '/' and dropping the flashed session data.
        $this->withHeader('Referer', config('app.url') . '/register');

        // Explicitly NOT authenticated. Laravel's test client is a guest by
        // default, but we assert that here so a future change to a shared setUp()
        // cannot silently authenticate this client and re-break the guest routes.
        $this->assertGuest();

        return $this;
    }

    /**
     * Dismiss the first-login onboarding modal if it is open.
     *
     * WHY THIS IS NEEDED ON ALMOST EVERY BROWSER TEST
     * -----------------------------------------------
     * The first-login guided tour works (see OnboardingProvider), so it opens on
     * top of the dashboard for ANY user who has never signed in. Its overlay is
     * `fixed inset-0 z-[100]`, which correctly intercepts clicks and paints over
     * the page beneath it.
     *
     * A test that forgets this fails in a MISLEADING way: either
     * `ElementClickInterceptedException` ("the click did not land") or a 20-second
     * timeout waiting for an element that IS on the page but sits UNDER the modal.
     * Neither reads as "the tour is open".
     *
     * It is registered as a BROWSER MACRO (see registerOnboardingDismissal) so every
     * `loginAs()` call dismisses the tour automatically. Patching ~40 individual
     * call sites would work once and then silently rot the next time a test is
     * added.
     */
    protected function dismissOnboarding(Browser $browser): void
    {
        /*
         * `whenAvailable` polls for the selector with a DEFAULT 5-SECOND TIMEOUT and
         * skips the callback if it never appears. That is wasteful on every test
         * where the tour is already dismissed by default (see makeTenantUser), and
         * the wait itself shows up in the log as noise.
         *
         * This checks the DOM ONCE via `script` instead: no waiting, no timeout, and
         * the click only happens when the modal is genuinely on screen. That keeps
         * the helper cheap enough to call defensively anywhere.
         */
        $present = $browser->script(
            "return document.querySelector('[data-testid=\"onboarding-modal\"]') ? 1 : 0;"
        )[0] ?? 0;

        if ((int) $present === 1) {
            $browser->click('[data-testid="onboarding-skip"]');
        }
    }

    /**
     * Wrap Browser::loginAs() so the first-login tour is dismissed automatically.
     *
     * WHY A WRAPPER AND NOT A HELPER CALL PER TEST
     * --------------------------------------------
     * The tour opens for ANY user who has never signed in. That is nearly every
     * fixture in this suite, so ~40 tests need the dismissal. Calling a helper at
     * each of those sites would work today and then silently break the next test
     * somebody adds - the failure mode being a confusing timeout or an intercepted
     * click, which does not point at the real cause.
     *
     * Wrapping `loginAs` makes the behaviour part of the harness: every test gets
     * it, forever, without opting in.
     *
     * IDEMPOTENT: the guard checks the closure's marker property so a class that
     * runs many tests does not stack wrappers on top of each other (which would
     * dismiss repeatedly and slow the suite down).
     */
    protected function registerOnboardingAutoDismiss(): void
    {
        if (Browser::hasMacro('dismissOnboardingIfOpen')) {
            return; // Already registered by a previous test in this process.
        }

        Browser::macro('dismissOnboardingIfOpen', function (): Browser {
            /** @var Browser $this */
            // whenAvailable skips the callback when the tour is not open, rather
            // than throwing the way waitFor does.
            return $this->whenAvailable('[data-testid="onboarding-modal"]', function (Browser $modal) {
                $modal->click('[data-testid="onboarding-skip"]');
            });
        });

        Browser::macro('loginAsAndDismissTour', function ($user): Browser {
            /** @var Browser $this */
            return $this->loginAs($user)->dismissOnboardingIfOpen();
        });
    }

    /**
     * Create an institution using ONLY the columns this app defines
     * (database/migrations: create_institutions_table + add_trial_lifecycle +
     * add_multitenancy_and_currency_settings).
     *
     * Defaults to a live 'subscription' workspace so a fixture never reads as
     * trialing unless a test opts in.
     */
    protected function makeInstitution(array $attributes = []): Institution
    {
        /*
         * Give each institution a UNIQUE name (and therefore slug) unless the
         * caller supplies one. institutions.slug is UNIQUE, and Str::slug(name)
         * is generated on save, so two default-named fixtures in one test would
         * collide with "UNIQUE constraint failed: institutions.slug".
         */
        $defaults = [
            'name' => 'North South University Dorm',
            'subtitle' => 'Shared meals, tracked',
            'type' => 'university_dorm',
            'timezone' => 'UTC',
            'is_active' => true,
            'onboarding_mode' => 'subscription',
            'subscription_status' => 'paid',
        ];

        if (! array_key_exists('name', $attributes)) {
            $defaults['name'] = 'Institution ' . Str::random(6);
        }

        return Institution::create(array_merge($defaults, $attributes));
    }

    /**
     * Create a tenant-bound user (Institution Admin / Meal Manager / Member) and
     * grant the given role.
     *
     * ONBOARDING IS MARKED COMPLETE BY DEFAULT.
     * ------------------------------------------
     * The first-login guided tour opens for any user with a NULL
     * `onboarding_completed_at`, and its overlay (`fixed inset-0 z-[100]`) covers
     * the whole page. That is correct product behaviour - but it means EVERY
     * browser test that logs in a fresh fixture would have its page hidden behind
     * a modal, failing with either ElementClickInterceptedException or a confusing
     * 20-second wait timeout for an element that IS rendered.
     *
     * So the DEFAULT here is "an existing user who has already seen the tour",
     * which is what the vast majority of tests actually want. The tour's own tests
     * (`tests/Browser/Onboarding`) opt IN by passing
     * `['onboarding_completed_at' => null]` - making the dependency explicit at the
     * one place that cares about it, instead of a silent global side effect.
     */
    protected function makeTenantUser(Institution $institution, string $role, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'institution_id' => $institution->id,
            'name' => 'Tenant User',
            'email' => 'tenant-user@example.test',
            'password' => 'password', // Let Laravel's Model casts handle hashing automatically
            'status' => 'active',
            'must_change_password' => false,
            'setup_completed_at' => now(),
            'onboarding_completed_at' => now(),
        ], $attributes));

        $user->assignRole($role);

        return $user;
    }

    /** Convenience: an Institution Admin. */
    protected function makeInstitutionAdmin(Institution $institution, array $attributes = []): User
    {
        return $this->makeTenantUser($institution, 'Institution Admin', array_merge([
            'name' => 'Institution Admin',
            'email' => 'ia@example.test',
        ], $attributes));
    }

    /** Convenience: a Meal Manager. */
    protected function makeMealManager(Institution $institution, array $attributes = []): User
    {
        return $this->makeTenantUser($institution, 'Meal Manager', array_merge([
            'name' => 'Meal Manager',
            'email' => 'mm@example.test',
        ], $attributes));
    }

    /**
     * Convenience: a Member.
     *
     * A Member is a User whose `students.user_id` points at them. Pass
     * $withMemberRecord = true to also create that linked Student row, which the
     * member dashboard/controllers resolve via User::studentRecord().
     */
    protected function makeMember(Institution $institution, array $attributes = [], bool $withMemberRecord = true): User
    {
        $user = $this->makeTenantUser($institution, 'Member', array_merge([
            'name' => 'Samira Member',
            'email' => 'member@example.test',
        ], $attributes));

        if ($withMemberRecord) {
            Student::create([
                'institution_id' => $institution->id,
                'user_id' => $user->id,
                'name' => $user->name,
                'roll' => 'NSU-1001',
                'status' => 'active',
            ]);
        }

        return $user;
    }

    /**
     * Create the GLOBAL Software Super Admin. institution_id is deliberately
     * NULL and the account holds only the global role - this is what places the
     * user in the cross-tenant "platform" context.
     */
    protected function makeSuperAdmin(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'institution_id' => null,
            'name' => 'Platform Owner',
            'email' => 'ssa@platform.test',
            'password' => Hash::make('password'),
            'status' => 'active',
            'must_change_password' => false,
            'setup_completed_at' => now(),
            // See makeTenantUser(): the tour is dismissed by default so it does not
            // cover the page in tests that are not about onboarding.
            'onboarding_completed_at' => now(),
        ], $attributes));

        $user->assignRole('Software Super Admin');

        return $user;
    }

    /**
     * A roster member (Student) inside an institution, optionally overseen by a
     * manager. Mirrors students columns: institution_id, user_id, manager_id,
     * name, roll, department_id, join_date, status.
     */
    protected function makeStudent(Institution $institution, array $attributes = []): Student
    {
        return Student::create(array_merge([
            'institution_id' => $institution->id,
            'name' => 'Roster Member',
            'roll' => 'NSU-2001',
            'status' => 'active',
        ], $attributes));
    }

    /** The named role row exists? (sanity assertion helper) */
    protected function roleExists(string $name): bool
    {
        return Role::where('name', $name)->where('guard_name', 'web')->exists();
    }
}
