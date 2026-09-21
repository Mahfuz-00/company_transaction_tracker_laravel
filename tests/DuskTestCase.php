<?php
namespace Tests;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Support\Collection;
use Laravel\Dusk\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\BeforeClass;

abstract class DuskTestCase extends BaseTestCase
{
    /**
     * Prepare for Dusk test execution.
     *
     * Boots a local ChromeDriver on the port shared with driver() below. In a
     * Sail/Docker environment the driver is provided by the container instead, so
     * we skip starting a second one.
     */
    #[BeforeClass]
    public static function prepare(): void
    {
        if (! static::runningInSail()) {
            static::startChromeDriver(['--port=9515']);
        }
    }

    /**
     * Create the RemoteWebDriver instance.
     *
     * The argument set below is what makes the suite reliable in CI as well as on
     * a developer desktop:
     *
     *   --window-size        A fixed 1920x1080 viewport (Dusk's default) so
     *                       responsive Tailwind breakpoints resolve identically on
     *                       every machine, which is what makes assertions on
     *                       desktop-only markup (`lg:` / `sm:`) deterministic.
     *   --headless=new       Headless Chrome (see hasHeadlessDisabled() to run
     *                       visibly while debugging).
     *   --no-sandbox etc.    Sandbox/dev-shm flags stop Chrome from crashing in
     *                       containerised CI runners.
     *   --disable-dev-shm-usage  Routes shared memory to /tmp; without it Chrome
     *                       can hang on large pages under Docker.
     *
     * To run with a visible browser for debugging, set DUSK_HEADLESS_DISABLED=true
     * in .env.dusk.local before invoking `php artisan dusk`.
     */
    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments(collect([
            $this->shouldStartMaximized() ? '--start-maximized' : '--window-size=1920,1080',
            '--disable-search-engine-choice-screen',
            '--disable-smooth-scrolling',
            // Stability flags: harmless locally, essential in headless CI.
            '--disable-dev-shm-usage',
            // ── Noise reduction (Windows GCM / TensorFlow / updater messages) ──
            '--log-level=3',                          // Only fatal errors
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
                // No sandbox: Chrome refuses to start as root (Docker/CI) without it.
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
