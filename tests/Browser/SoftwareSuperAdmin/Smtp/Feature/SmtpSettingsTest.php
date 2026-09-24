<?php

namespace Tests\Browser\SoftwareSuperAdmin\Smtp\Feature;

use App\Models\PlatformSetting;
use App\Support\MailSettings;
use Illuminate\Support\Facades\Crypt;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * SOFTWARE SUPER ADMIN → PLATFORM SETTINGS → SMTP.
 *
 * Routes (routes/web.php, gated by `role:Software Super Admin`):
 *   GET  /platform/smtp       (`ssa.smtp.edit`)
 *   PUT  /platform/smtp       (`ssa.smtp.update`)
 *   POST /platform/smtp/test  (`ssa.smtp.test`)
 *
 * The module is strictly SSA-only: no other role (Institution Admin / Meal
 * Manager / Member) may read or change the platform mail relay. The form is
 * PRE-FILLED with the production Brevo relay (server, port, login), and the
 * password is stored ENCRYPTED and never returned to the browser.
 */
class SmtpSettingsTest extends DuskTestCase
{
    use DuskSupport;

    public function test_the_platform_owner_can_open_the_smtp_module_prefilled_with_brevo(): void
    {
        $this->seedRbac();

        // The permanent platform owner account.
        $ssa = $this->makeSuperAdmin(['email' => 'admin@mahfuz.com']);

        $this->step('SoftwareSuperAdmin', 'SMTP', 'GET the settings page', __LINE__);

        $this->httpAs($ssa)->get('/platform/smtp')->assertOk();

        // The Brevo production relay is pre-populated (server / port / login).
        $settings = MailSettings::forDisplay();
        $this->assertSame('smtp-relay.brevo.com', $settings['host']);
        $this->assertSame(587, (int) $settings['port']);
        $this->assertSame('b9c6fb001@smtp-brevo.com', $settings['username']);
        $this->assertFalse($settings['enabled']);
    }

    public function test_the_super_admin_can_save_smtp_settings_and_the_password_is_encrypted(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        $this->step('SoftwareSuperAdmin', 'SMTP', 'PUT new relay settings', __LINE__);

        $this->httpAs($ssa)
            ->put('/platform/smtp', [
                'enabled' => true,
                'host' => 'smtp-relay.brevo.com',
                'port' => 587,
                'username' => 'b9c6fb001@smtp-brevo.com',
                'password' => 'brevo-secret-key',
                'encryption' => 'tls',
                'from_address' => 'no-reply@mahfuz.com',
                'from_name' => 'Platform',
            ])
            ->assertSessionHas('success');

        // Stored ENCRYPTED, never in plaintext, and never exposed to the UI.
        $payload = PlatformSetting::where('key', MailSettings::KEY)->firstOrFail()->value;
        $this->assertNotSame('brevo-secret-key', $payload['password']);
        $this->assertSame('brevo-secret-key', Crypt::decryptString($payload['password']));

        $this->assertSame('brevo-secret-key', MailSettings::all()['password']);
        $this->assertTrue(MailSettings::forDisplay()['has_password']);
        $this->assertSame('', MailSettings::forDisplay()['password']);
    }

    public function test_a_blank_password_keeps_the_stored_secret(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        // Enabled => the connection fields are contextually REQUIRED, so the
        // payload must be complete; only the password is left blank on the 2nd.
        $this->httpAs($ssa)->put('/platform/smtp', [
            'enabled' => true, 'host' => 'smtp-relay.brevo.com', 'port' => 587,
            'username' => 'b9c6fb001@smtp-brevo.com', 'from_address' => 'no-reply@mahfuz.com',
            'password' => 'first-secret',
        ])->assertSessionHas('success');

        $this->httpAs($ssa)->put('/platform/smtp', [
            'enabled' => true, 'host' => 'smtp.relay.two.test', 'port' => 465, 'encryption' => 'ssl',
            'username' => 'b9c6fb001@smtp-brevo.com', 'from_address' => 'no-reply@mahfuz.com',
        ])->assertSessionHas('success');

        $this->assertSame('smtp.relay.two.test', MailSettings::all()['host']);
        $this->assertSame('first-secret', MailSettings::all()['password']);
    }

    public function test_enabling_smtp_without_the_connection_fields_is_rejected(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        // CONTEXTUAL VALIDATION: with SMTP enabled and no relay details, the
        // save must fail and nothing may be persisted.
        $this->httpAs($ssa)
            ->put('/platform/smtp', ['enabled' => true])
            ->assertSessionHasErrors(['host', 'port', 'username', 'from_address']);

        $this->assertDatabaseCount('platform_settings', 0);
    }

    public function test_the_super_admin_can_send_a_test_email(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        // Keep the test hermetic: the 'array' transport records the message
        // instead of opening a real SMTP connection.
        config(['mail.default' => 'array']);

        $this->step('SoftwareSuperAdmin', 'SMTP', 'send a test email', __LINE__);

        $this->httpAs($ssa)
            ->post('/platform/smtp/test', ['test_email' => 'recipient@example.test'])
            ->assertSessionHas('success');

        $transport = \Illuminate\Support\Facades\Mail::mailer()->getSymfonyTransport();
        $this->assertInstanceOf(\Illuminate\Mail\Transport\ArrayTransport::class, $transport);
        $this->assertCount(1, $transport->messages());
    }

    public function test_an_institution_admin_is_refused_the_smtp_module(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('InstitutionAdmin', 'SMTP', 'expect 403', __LINE__);

        $this->httpAs($admin)->get('/platform/smtp')->assertForbidden();
        $this->httpAs($admin)->put('/platform/smtp', ['host' => 'smtp.evil.test'])->assertForbidden();
        $this->httpAs($admin)->post('/platform/smtp/test', ['test_email' => 'x@y.test'])->assertForbidden();

        $this->assertDatabaseCount('platform_settings', 0);
    }

    public function test_a_meal_manager_is_refused_the_smtp_module(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $manager = $this->makeMealManager($institution);

        $this->httpAs($manager)->get('/platform/smtp')->assertForbidden();
        $this->httpAs($manager)->put('/platform/smtp', ['host' => 'smtp.evil.test'])->assertForbidden();
    }
}
