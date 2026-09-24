<?php

namespace App\Support;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * DYNAMIC SMTP / MAIL CONFIGURATION.
 *
 * The platform's outbound mail was previously driven only by `.env` values,
 * which meant changing the SMTP relay required editing env vars and redeploying.
 * This service lets the Software Super Admin configure it from the UI instead:
 *
 *   - settings live in the `platform_settings` table (key `mail`),
 *   - the password is stored ENCRYPTED at rest (never logged, never echoed back),
 *   - the runtime `mail.*` config is overridden on boot so every mailable uses
 *     the relay immediately, with no deploy.
 *
 * The defaults are pre-filled with the production BREVO relay (server, port and
 * login), so the SSA only has to add the password and enable it.
 *
 * SAFETY: every read is wrapped so a missing table (during a fresh migrate) or a
 * bad decrypt can never take down a request - it simply falls back to the env
 * configuration.
 */
class MailSettings
{
    /** The `platform_settings` row key holding the mail payload. */
    public const KEY = 'mail';

    /** Cache key so we hit the database at most once per deploy/change. */
    public const CACHE_KEY = 'platform.mail_settings';

    /** Production Brevo relay defaults (pre-populated in the SSA form). */
    public const BREVO_HOST = 'smtp-relay.brevo.com';
    public const BREVO_PORT = 587;
    public const BREVO_USERNAME = 'b9c6fb001@smtp-brevo.com';

    /** The shape + defaults of a complete configuration. */
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'host' => self::BREVO_HOST,
            'port' => self::BREVO_PORT,
            'username' => self::BREVO_USERNAME,
            'password' => '',
            'encryption' => 'tls', // tls | ssl | none
            'from_address' => '',
            'from_name' => '',
        ];
    }

    /**
     * The stored configuration, with the password DECRYPTED (in memory only).
     * Falls back to the Brevo defaults when nothing has been saved yet.
     */
    public static function all(): array
    {
        $stored = static::stored();

        $password = '';

        if (filled($stored['password'] ?? null)) {
            try {
                $password = Crypt::decryptString((string) $stored['password']);
            } catch (\Throwable $e) {
                // A rotated APP_KEY (or corrupt value) simply leaves the secret
                // blank rather than crashing the mail path.
                $password = '';
            }
        }

        return array_merge(static::defaults(), $stored, ['password' => $password]);
    }

    /**
     * The safe shape for the UI: the password secret is never sent to the
     * browser - only a boolean telling the form that one is already stored.
     */
    public static function forDisplay(): array
    {
        $settings = static::all();
        $settings['has_password'] = filled($settings['password']);
        $settings['password'] = '';

        return $settings;
    }

    /** Raw stored payload (password still encrypted). */
    protected static function stored(): array
    {
        try {
            $value = Cache::rememberForever(static::CACHE_KEY, function () {
                return PlatformSetting::query()->where('key', static::KEY)->first()?->value ?: [];
            });

            return is_array($value) ? $value : [];
        } catch (\Throwable $e) {
            // Table not migrated yet / cache store unavailable -> use defaults.
            return [];
        }
    }

    /**
     * Persist a new configuration. A blank password keeps the existing secret,
     * so the SSA can tweak the host/port without re-typing the credential.
     */
    public static function save(array $data): void
    {
        $current = static::stored();

        $payload = [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'host' => trim((string) ($data['host'] ?? '')),
            'port' => (int) ($data['port'] ?? static::BREVO_PORT),
            'username' => trim((string) ($data['username'] ?? '')),
            'encryption' => (string) ($data['encryption'] ?? 'tls'),
            'from_address' => trim((string) ($data['from_address'] ?? '')),
            'from_name' => trim((string) ($data['from_name'] ?? '')),
        ];

        if (filled($data['password'] ?? null)) {
            $payload['password'] = Crypt::encryptString((string) $data['password']);
        } else {
            // Preserve the stored (encrypted) secret.
            $payload['password'] = $current['password'] ?? null;
        }

        PlatformSetting::updateOrCreate(['key' => static::KEY], ['value' => $payload]);

        Cache::forget(static::CACHE_KEY);
    }

    /**
     * Apply the stored configuration to the runtime mail config, so the very
     * next mailable uses it. A no-op when SMTP is disabled or hostless - the
     * env-configured mailer stays in charge.
     */
    public static function apply(): void
    {
        $settings = static::all();

        if (! ($settings['enabled'] ?? false) || blank($settings['host'] ?? null)) {
            return;
        }

        $encryption = $settings['encryption'] ?? 'tls';

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $settings['host'],
            'mail.mailers.smtp.port' => (int) $settings['port'],
            'mail.mailers.smtp.username' => $settings['username'] ?: null,
            'mail.mailers.smtp.password' => $settings['password'] ?: null,
            // Laravel 11+ selects the transport via `scheme`: 'smtps' = implicit
            // TLS (port 465); null = plain/STARTTLS negotiation (port 587/25).
            'mail.mailers.smtp.scheme' => $encryption === 'ssl' ? 'smtps' : null,
        ]);

        if (filled($settings['from_address'] ?? null)) {
            config(['mail.from.address' => $settings['from_address']]);
        }

        if (filled($settings['from_name'] ?? null)) {
            config(['mail.from.name' => $settings['from_name']]);
        }
    }
}
