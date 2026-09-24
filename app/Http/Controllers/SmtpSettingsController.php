<?php

namespace App\Http\Controllers;

use App\Support\AuditLogger;
use App\Support\MailSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Software Super Admin only: SMTP / Mail configuration.
 *
 * A dedicated sub-module of the SSA settings that points the whole platform at
 * an SMTP relay and sends a test email - all without a redeploy. Access is
 * restricted TWICE: the routes carry the `role:Software Super Admin` gate and
 * every action re-asserts isSuperAdmin() here, so a leaked or forged request
 * still cannot read or change the relay. No other role reaches this module.
 *
 * The form is pre-filled with the production Brevo relay (server, port, login).
 */
class SmtpSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $this->authorise($request);

        return Inertia::render('SSA/SmtpSettings', [
            'settings' => MailSettings::forDisplay(),
            // Reports whether the platform is running on the stored relay or on
            // the fallback .env mailer, so the SSA sees the effective state.
            'effectiveDriver' => config('mail.default'),
        ]);
    }

    public function update(Request $request)
    {
        $this->authorise($request);

        $data = $request->validate([
            'enabled' => ['boolean'],
            /*
             * CONTEXTUAL VALIDATION (UI-state driven, columns stay nullable).
             *
             * While SMTP is DISABLED every field is optional - the platform
             * simply falls back to the .env mailer. The moment the SSA switches
             * it ON, the connection fields become mandatory, so a half-configured
             * relay can never be saved and silently break all outbound mail.
             */
            'host' => ['nullable', 'string', 'max:255', Rule::requiredIf($request->boolean('enabled'))],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535', Rule::requiredIf($request->boolean('enabled'))],
            'username' => ['nullable', 'string', 'max:255', Rule::requiredIf($request->boolean('enabled'))],
            // Required unless a secret is already stored (blank = keep existing).
            'password' => ['nullable', 'string', 'max:255', Rule::requiredIf(
                fn () => $request->boolean('enabled') && ! MailSettings::forDisplay()['has_password']
            )],
            'encryption' => ['nullable', Rule::in(['tls', 'ssl', 'none'])],
            'from_address' => ['nullable', 'email', 'max:255', Rule::requiredIf($request->boolean('enabled'))],
            'from_name' => ['nullable', 'string', 'max:120'],
        ]);

        MailSettings::save($data);

        // Point the current process at the new relay immediately so a follow-up
        // "send test email" in the same request uses it.
        MailSettings::apply();

        AuditLogger::log('updated', 'updated SMTP / mail settings', null, [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'host' => $data['host'] ?? null,
            'port' => $data['port'] ?? null,
            'encryption' => $data['encryption'] ?? null,
            'from_address' => $data['from_address'] ?? null,
            // `password` is intentionally never logged.
        ], ['subject_label' => 'SMTP Settings']);

        return back()->with('success', 'SMTP settings saved.');
    }

    /**
     * Send a test email through the CURRENTLY SAVED relay so the SSA can verify
     * the configuration end-to-end.
     */
    public function sendTest(Request $request)
    {
        $this->authorise($request);

        $data = $request->validate([
            'test_email' => ['required', 'email', 'max:255'],
        ]);

        try {
            // Re-apply in case settings were changed in this same request.
            MailSettings::apply();

            $driver = config('mail.default');

            Mail::raw(
                "This is a test email from the platform's SMTP configuration.\n\n"
                . "Mailer: {$driver}\n"
                . 'Sent at: ' . now()->toDateTimeString(),
                function ($message) use ($data) {
                    $message->to($data['test_email'])->subject('SMTP test email');
                }
            );
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Test email failed: ' . $e->getMessage());
        }

        return back()->with('success', "Test email sent to {$data['test_email']}.");
    }

    /** Defence in depth: only the global Software Super Admin may proceed. */
    protected function authorise(Request $request): void
    {
        abort_unless(
            $request->user()?->isSuperAdmin(),
            403,
            'Only the Software Super Admin can manage SMTP settings.'
        );
    }
}
