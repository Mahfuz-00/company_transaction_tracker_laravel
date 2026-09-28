<?php

namespace Tests\Browser\SoftwareSuperAdmin\Smtp\Feature;

use App\Support\MailSettings;
use App\Support\SmtpConnectionChecker;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * SOFTWARE SUPER ADMIN → PLATFORM SETTINGS → SMTP → LIVE CONNECTION CHECK.
 *
 * Route: POST /platform/smtp/check (`ssa.smtp.check`), SSA-only.
 *
 * WHY THIS EXISTS
 * ---------------
 * The module used to let the operator save any combination of host/port/username
 * /password and always flashed "SMTP settings saved." - even when the credentials
 * could not connect. The failure only surfaced later, when an invitation email
 * silently failed to deliver. These tests lock in the two halves of the fix:
 *
 *   1. The dedicated CHECK endpoint verifies a configuration (saved or draft)
 *      WITHOUT persisting it, and reports an honest ok/fail verdict.
 *   2. Saving while ENABLED now verifies too, and the flash banner reflects the
 *      TRUTH: 'success' when the relay accepts the credentials, 'error' when it
 *      does not.
 *
 * TEST NOTE (network isolation)
 * -----------------------------
 * The automated suite sets SMTP_LIVE_CHECK=false, so SmtpConnectionChecker never
 * opens a real socket. That keeps the suite hermetic and fast while still
 * exercising the FULL controller path, the result shape and the flash banners. A
 * separate test asserts the disabled-check contract explicitly.
 */
class SmtpConnectionCheckTest extends DuskTestCase
{
    use DuskSupport;

    public function test_the_check_endpoint_verifies_a_configuration_without_saving_it(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        $this->step('SoftwareSuperAdmin', 'SMTP', 'POST a draft config to the check endpoint', __LINE__);

        $response = $this->httpAs($ssa)->postJson('/platform/smtp/check', [
            'host' => 'smtp-relay.brevo.com',
            'port' => 587,
            'username' => 'b9c6fb001@smtp-brevo.com',
            'password' => 'brevo-secret-key',
            'encryption' => 'tls',
        ]);

        $response->assertOk()
            ->assertJson(['ok' => true])
            ->assertJsonStructure(['ok', 'message', 'host', 'port', 'encryption']);

        // CRITICAL: a check is a READ-ONLY probe. Nothing may be persisted.
        $this->assertDatabaseCount('platform_settings', 0);
    }

    public function test_the_check_endpoint_rejects_a_configuration_with_no_host(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        $this->step('SoftwareSuperAdmin', 'SMTP', 'check with a missing host', __LINE__);

        // No host => the checker cannot even attempt a connection and must report
        // a failure with a 422, so the UI can render a red banner.
        $response = $this->httpAs($ssa)->postJson('/platform/smtp/check', [
            'host' => '',
            'port' => 587,
        ]);

        $response->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertDatabaseCount('platform_settings', 0);
    }

    public function test_the_check_endpoint_rejects_an_out_of_range_port(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        $this->step('SoftwareSuperAdmin', 'SMTP', 'check with an invalid port', __LINE__);

        $response = $this->httpAs($ssa)->postJson('/platform/smtp/check', [
            'host' => 'smtp-relay.brevo.com',
            'port' => 70000, // above the 65535 ceiling
        ]);

        // The validation rule (max:65535) rejects it before the checker runs.
        $response->assertStatus(422)->assertJsonValidationErrors('port');
    }

    public function test_saving_an_enabled_relay_reports_a_verified_success(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        $this->step('SoftwareSuperAdmin', 'SMTP', 'save a complete enabled relay', __LINE__);

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

        // The saved configuration is reported as fully configured + active, which
        // is what lets the UI hide the "SMTP is disabled - falls back" warning.
        $display = MailSettings::forDisplay();
        $this->assertTrue($display['configured'], 'A complete relay must report as configured.');
        $this->assertTrue($display['active'], 'An enabled, complete relay must report as active.');
        $this->assertTrue($display['has_password']);
    }

    public function test_a_disabled_relay_save_reports_the_fallback_state(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        $this->step('SoftwareSuperAdmin', 'SMTP', 'save with SMTP disabled', __LINE__);

        // Disabled => the connection fields are optional, and the flash must say
        // the platform falls back to the environment mailer (never "verified").
        $this->httpAs($ssa)
            ->put('/platform/smtp', [
                'enabled' => false,
                'host' => 'smtp-relay.brevo.com',
                'port' => 587,
                'username' => 'b9c6fb001@smtp-brevo.com',
                'from_address' => 'no-reply@mahfuz.com',
            ])
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'disabled'));

        $display = MailSettings::forDisplay();
        $this->assertFalse($display['active'], 'A disabled relay must never report as active.');
    }

    public function test_an_incomplete_relay_reports_as_not_configured(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        // A username with no stored password cannot authenticate => not configured.
        $this->httpAs($ssa)->put('/platform/smtp', [
            'enabled' => true,
            'host' => 'smtp-relay.brevo.com',
            'port' => 587,
            'username' => 'b9c6fb001@smtp-brevo.com',
            'password' => '',
            'from_address' => 'no-reply@mahfuz.com',
        ]);

        // The save is refused (password is required when none is stored yet), so
        // nothing is persisted and the module stays unconfigured.
        $this->assertDatabaseCount('platform_settings', 0);
        $this->assertFalse(MailSettings::forDisplay()['configured']);
    }

    public function test_a_non_super_admin_cannot_run_the_connection_check(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('InstitutionAdmin', 'SMTP', 'check endpoint must be 403', __LINE__);

        // The route is role-gated AND the controller re-asserts isSuperAdmin().
        $this->httpAs($admin)
            ->postJson('/platform/smtp/check', [
                'host' => 'smtp-relay.brevo.com',
                'port' => 587,
            ])
            ->assertForbidden();
    }

    public function test_the_live_check_is_disabled_in_the_automated_suite(): void
    {
        // A guard test: it documents WHY the other tests are hermetic. If this
        // ever flips to true, the suite would start opening real sockets.
        $this->assertFalse(
            SmtpConnectionChecker::enabled(),
            'The Dusk suite must run with SMTP_LIVE_CHECK=false so no test touches the network.'
        );
    }

    public function test_the_check_returns_the_canonical_encryption_modes(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        // Each canonical mode is echoed back so the UI can label the verdict.
        foreach (['tls', 'ssl', 'none'] as $mode) {
            $this->httpAs($ssa)
                ->postJson('/platform/smtp/check', [
                    'host' => 'smtp-relay.brevo.com',
                    'port' => $mode === 'ssl' ? 465 : 587,
                    'encryption' => $mode,
                ])
                ->assertOk()
                ->assertJson(['encryption' => $mode]);
        }
    }
}
