<?php

namespace Tests;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Support\Collection;
use Laravel\Dusk\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\BeforeClass;
use Tests\Browser\Support\DuskDatabase;

abstract class DuskTestCase extends BaseTestCase
{
    /*
     * DuskDatabase commits the schema + fixtures so the SEPARATE `artisan serve`
     * process the browser talks to can actually SEE the test data. Without it a
     * real form login (rather than loginAs) can never resolve the account and the
     * suite hangs on waitForLocation().
     */
    use DuskDatabase;

    #[BeforeClass]
    public static function prepare(): void
    {
        if (! static::runningInSail()) {
            static::startChromeDriver(['--port=9515']);
        }
    }

    /**
     * Build a committed, server-visible database before each test, then wipe the
     * domain tables so tests stay independent without a rollback transaction
     * (a rollback would hide the data from the server process again).
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->migrateDuskDatabase();

        $this->useCompiledAssetsForDusk();

        /*
         * AUTO-DISMISS THE FIRST-LOGIN TOUR ON EVERY loginAs().
         *
         * The guided tour is shown to any user who has never signed in, and its
         * overlay (`fixed inset-0 z-[100]`) covers the page. Without this, every
         * browser test that logs in a fresh fixture fails with either
         * ElementClickInterceptedException or a 20-second wait timeout for an
         * element that IS rendered - just painted underneath the modal. Both read
         * like a broken page rather than "the tour is open".
         *
         * GUARDED BY method_exists BECAUSE NOT EVERY SUBCLASS USES THE TRAIT.
         * The macro registration lives in DuskSupport, which most test classes
         * `use` - but a test that needs no fixtures (e.g. the database-protection
         * guard) does not. Calling it unconditionally produced
         * "Call to undefined method ...::registerOnboardingAutoDismiss()" in
         * setUp(), failing every test in such a class before it ran.
         *
         * The base class must not require a trait an optional subclass may omit.
         */
        if (method_exists($this, 'registerOnboardingAutoDismiss')) {
            $this->registerOnboardingAutoDismiss();
        }


        /*
         * FLUSH THE SPATIE PERMISSION CACHE BEFORE EACH TEST.
         *
         * spatie/laravel-permission caches the ROLE -> PERMISSION map for 24 hours
         * (config/permission.php). That cache is shared with the separate
         * `artisan serve` process the browser talks to, so a role granted in THIS
         * test (e.g. assigning 'Meal Manager' to a fixture) would still be checked
         * against a stale map - and a permission the role genuinely holds would be
         * reported as missing.
         *
         * The symptom is a confusing 403 on a route the user should reach. Clearing
         * the cache here makes each test see the roles as they are NOW.
         */
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        /*
         * Several tests ALSO make Laravel HTTP calls directly (actingAs()->post())
         * to assert server-side effects (a flash message, a 403, a DB write). Those
         * calls run through the full HTTP kernel, so CSRF would reject them with a
         * 419. Disabling ONLY the CSRF middleware keeps every other middleware
         * (auth, role:, permission:) active, so the security assertions still hold.
         */
        /*
         * Several tests ALSO make Laravel HTTP calls directly (actingAs()->post())
         * to assert server-side effects (a flash message, a 403, a DB write). Those
         * calls run through the full HTTP kernel, so CSRF would reject them with a
         * 419. Disabling ONLY the CSRF middleware keeps every other middleware
         * (auth, role:, permission:) active, so the security assertions still hold.
         *
         * BOTH CLASSES ARE NAMED DELIBERATELY. Laravel 11/12 renamed
         * VerifyCsrfToken to ValidateCsrfToken, and `withoutMiddleware()` matches by
         * EXACT class name - so disabling only the old one silently let a 419
         * through in the guest tests that POST directly (assistant.ask), producing
         * a failure that looks like broken routing. DuskSupport::httpAs() already
         * disables both; this keeps the base class consistent with it.
         */
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
        ]);
    }

    /**
     * SERVE THE COMPILED BUILD TO THE BROWSER, NOT THE VITE DEV SERVER.
     *
     * WHY THIS IS NECESSARY
     * ---------------------
     * Laravel's Vite integration switches to the dev server whenever
     * `public/hot` exists, reading the URL out of that file. On this machine Vite
     * binds to IPv6 loopback (`http://[::1]:5173`), which the ChromeDriver-managed
     * Chrome cannot reach - so the module bundle never loads, React never mounts,
     * and EVERY browser test fails with a 20-second `waitFor` timeout for text
     * that is in fact rendered by the component. The failure looks like a broken
     * page rather than a network problem, which is what makes it so misleading.
     *
     * The compiled build in `public/build` is a static, dependency-free bundle
     * that any browser can load, so pointing the suite at it makes the browser
     * tests HERMETIC: they no longer depend on a dev server running, on which
     * port/stack it bound to, or on the machine's IPv6 configuration.
     *
     * HOW IT WORKS
     * ------------
     * `Vite::useHotFile()` swaps the hot-file path for the duration of the
     * process. Pointing it at a path that does not exist makes `isRunningHot()`
     * false (Laravel checks `file_exists`), so the compiled manifest is used
     * instead. Nothing is written to disk and the developer's real
     * `public/hot` - and their running Vite server - are left untouched.
     *
     * If no build exists yet, the helper says so rather than letting the suite
     * fail with a confusing blank page: the fix is `npm run build`.
     */
    protected function useCompiledAssetsForDusk(): void
    {
        $manifest = public_path('build/manifest.json');

        if (! file_exists($manifest)) {
            fwrite(
                STDERR,
                PHP_EOL.'[dusk] No compiled assets found at public/build/manifest.json.'.PHP_EOL
                .'[dusk] Run `npm run build` (or `npm run dev`) before the browser suite.'.PHP_EOL
            );

            return;
        }

        \Illuminate\Support\Facades\Vite::useHotFile(
            storage_path('framework/dusk-no-hot-file')
        );
    }

    protected function tearDown(): void
    {
        /*
         * Drop the browser's cookies/session between tests.
         *
         * Dusk reuses ONE browser instance for the whole test class, so a test
         * that logs a user in leaves that session cookie in place. The next test
         * then hits the `guest` middleware (e.g. on /login) and is redirected away
         * from the page it wanted to assert on - which looks like a text timeout.
         * Clearing cookies restores a clean guest session per test.
         */
        /*
         * Dusk's ProvidesBrowser declares `static $browsers = []` (a plain
         * array) and only upgrades it to a Collection inside browse(). A test
         * that never drives the browser (pure server-side httpAs() assertions)
         * therefore still holds an ARRAY here, and calling ->isNotEmpty() on it
         * threw "Call to a member function isNotEmpty() on array" - which
         * aborted tearDown() BEFORE the tables were truncated, leaking rows into
         * the next test (e.g. a duplicate users.email). Normalise to a collection
         * and iterate defensively so both shapes work.
         */
        $browsers = collect(static::$browsers);

        if ($browsers->isNotEmpty()) {
            $browsers->each(function ($browser) {
                try {
                    $browser->driver->manage()->deleteAllCookies();
                } catch (\Throwable $e) {
                    // Browser may already be closed; nothing to clear.
                }
            });
        }

        // Leave a clean slate for the next test (committed, so the server agrees).
        $this->truncateDuskTables();

        parent::tearDown();
    }

    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments(collect([
            $this->shouldStartMaximized() ? '--start-maximized' : '--window-size=1920,1080',
            '--disable-search-engine-choice-screen',
            '--disable-smooth-scrolling',
            '--disable-dev-shm-usage',
            '--log-level=3',
            '--disable-background-networking',
            '--disable-background-timer-throttling',
            '--disable-backgrounding-occluded-windows',
            '--disable-breakpad',
            '--disable-client-side-phishing-detection',
            '--disable-component-update',
            '--disable-default-apps',
            '--disable-domain-reliability',
            '--disable-features=TranslateUI,BlinkGenPropertyTrees,AudioServiceOutOfProcess',
            '--disable-hang-monitor',
            '--disable-ipc-flooding-protection',
            '--disable-notifications',
            '--disable-popup-blocking',
            '--disable-prompt-on-repost',
            '--disable-renderer-backgrounding',
            '--disable-sync',
            '--metrics-recording-only',
            '--no-first-run',
            '--safebrowsing-disable-auto-update',
            '--password-store=basic',
            '--use-mock-keychain',
        ])->unless($this->hasHeadlessDisabled(), function (Collection $items) {
            return $items->merge([
                '--disable-gpu',
                '--headless=new',
                '--no-sandbox',
            ]);
        })->all());

        return RemoteWebDriver::create(
            $_ENV['DUSK_DRIVER_URL'] ?? env('DUSK_DRIVER_URL') ?? 'http://localhost:9515',
            DesiredCapabilities::chrome()->setCapability(
                ChromeOptions::CAPABILITY, $options
            )
        );
    }
}