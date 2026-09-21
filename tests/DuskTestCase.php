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

        /*
         * Several tests ALSO make Laravel HTTP calls directly (actingAs()->post())
         * to assert server-side effects (a flash message, a 403, a DB write). Those
         * calls run through the full HTTP kernel, so CSRF would reject them with a
         * 419. Disabling ONLY the CSRF middleware keeps every other middleware
         * (auth, role:, permission:) active, so the security assertions still hold.
         */
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);
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