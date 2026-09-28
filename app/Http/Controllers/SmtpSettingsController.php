<?php

namespace App\Http\Controllers;

use App\Support\AuditLogger;
use App\Support\MailSettings;
use App\Support\SmtpConnectionChecker;
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

    /**
     * LIVE CONNECTION CHECK (ping) - does NOT save anything.
     *
     * The SSA can verify a configuration before committing it. The values are
     * taken from the request when supplied (so an UNSAVED draft can be tested),
     * and fall back to the stored relay otherwise. A blank password is resolved to
     * the stored secret by the checker, matching what the form means by "leave
     * blank to keep".
     *
     * Returns JSON so the React form can show an inline success/failure banner
     * without a full page round-trip.
     */
    public function check(Request $request)
    {
        $this->authorise($request);

        $data = $request->validate([
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'encryption' => ['nullable', Rule::in(['tls', 'ssl', 'none'])],
        ]);

        /*
         * Merge the draft over the stored configuration, so a partially-filled
         * form still tests against the parts the operator has not touched.
         *
         * HOST AND PORT ARE MERGED UNCONDITIONALLY (NOT FILTERED).
         * -------------------------------------------------------
         * A blank field normally means "leave this as it is", which is why most keys
         * are dropped before the merge. That is WRONG for `host` and `port`:
         *
         *   - `MailSettings::all()` always supplies a DEFAULT host (the Brevo relay),
         *     so dropping a blank `host` silently substituted `smtp-relay.brevo.com`
         *     for the value the operator had just CLEARED. The check then reported a
         *     confident green "connected" verdict for a configuration with no host
         *     at all - exactly the false assurance this endpoint exists to prevent.
         *
         *   - The same applies to `port`: a cleared port must be reported as invalid
         *     rather than quietly restored to 587.
         *
         * The operator explicitly submitted these fields, so the submitted value is
         * what must be verified - including when it is empty.
         */
        $stored = MailSettings::all();

        $draft = array_filter(
            $data,
            fn ($value) => $value !== null && $value !== '',
        );

        // Honour an explicitly-submitted host/port even when blank.
        foreach (['host', 'port'] as $required) {
            if (array_key_exists($required, $data)) {
                $draft[$required] = $data[$required];
            }
        }

        $config = array_merge($stored, $draft);

        $result = SmtpConnectionChecker::verify($config);

        AuditLogger::log('updated', 'ran an SMTP connection check', null, [
            'host' => $result['host'],
            'port' => $result['port'],
            'encryption' => $result['encryption'],
            'ok' => $result['ok'],
        ], ['subject_label' => 'SMTP Settings']);

        return response()->json($result, $result['ok'] ? 200 : 422);
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

        /*
         * LIVE VERIFICATION BEFORE REPORTING SUCCESS.
         *
         * The old handler always flashed "SMTP settings saved." even when the
         * credentials could not connect - so the operator believed mail worked
         * until an invitation silently failed. Now, when SMTP is ENABLED we ping
         * the relay with the just-saved values and report the truth:
         *   - connected  -> success banner (accurate, verified),
         *   - unreachable-> error banner naming the reason.
         * The settings are still saved either way, so the operator can correct and
         * retry without losing their input.
         */
        if (! $request->boolean('enabled')) {
            return back()->with('success', 'SMTP settings saved. SMTP is disabled - the platform will use the environment mailer.');
        }

        $result = SmtpConnectionChecker::verify(MailSettings::all());

        if (! $result['ok']) {
            return back()->with('error', 'Settings saved, but the connection check failed. ' . $result['message']);
        }

        return back()->with('success', 'SMTP settings saved and the connection was verified. ' . $result['message']);
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

            /*
             * Live connection check FIRST, so a failure is reported as a
             * connection problem (with the real reason) rather than as a vague
             * "test email failed". Skipped for non-SMTP transports (array / log),
             * which are local and always available - that keeps the automated
             * test suite (and local dev) able to send without a live relay.
             */
            if ($driver === 'smtp') {
                $check = SmtpConnectionChecker::verify(MailSettings::all());

                if (! $check['ok']) {
                    return back()->with('error', 'Test email not sent - the SMTP connection failed. ' . $check['message']);
                }
            }

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
