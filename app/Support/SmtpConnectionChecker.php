<?php

namespace App\Support;

use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * LIVE SMTP CONNECTION CHECK.
 *
 * The SMTP settings form previously let the Software Super Admin SAVE any
 * combination of host/port/username/password and only discover it was wrong
 * when a real invitation email silently failed. This service answers the
 * question the operator actually cares about - "does this configuration really
 * connect to the mail provider?" - BEFORE (or without) saving.
 *
 * HOW IT VERIFIES
 * ---------------
 * It builds a Symfony SMTP transport from the SUPPLIED values (not the stored
 * ones, so an unsaved draft can be tested) and forces it to open the socket and
 * complete the SMTP handshake (`start()`), which performs:
 *
 *   - TCP connect to host:port,
 *   - implicit TLS for `ssl` (SmtpsTransport / scheme=smtps),
 *   - EHLO + STARTTLS negotiation for `tls`,
 *   - AUTH LOGIN / AUTH PLAIN when a username + password are given.
 *
 * Any authentication, DNS, TLS or port failure throws, and the exception message
 * is returned as the reason - so the operator sees "535 Authentication failed"
 * rather than a generic "saved".
 *
 * It NEVER sends a message and NEVER mutates the stored configuration, so it is
 * safe to call on every keystroke-adjacent action. A short timeout keeps the
 * request responsive when a host is unreachable.
 */
class SmtpConnectionChecker
{
    /** How long to wait for the connection/handshake, in seconds. */
    public const TIMEOUT = 10;

    /**
     * Is a live connection check allowed in this environment?
     *
     * Real socket I/O is meaningless (and slow/flaky) inside the automated test
     * suite, and would make every SMTP save attempt a real network call. The
     * suite sets `services.smtp.live_check = false` so a save/test reports the
     * intended outcome without touching the network. Production leaves it true.
     */
    public static function enabled(): bool
    {
        return (bool) config('services.smtp.live_check', true);
    }

    /**
     * Attempt a live connection with the given credentials.
     *
     * @param  array{host?:?string,port?:int|string|null,username?:?string,password?:?string,encryption?:?string}  $config
     * @return array{ok:bool,message:string,driver:string,host:?string,port:?int,encryption:string,transport:string}
     */
    public static function verify(array $config): array
    {
        $host = trim((string) ($config['host'] ?? ''));
        $port = (int) ($config['port'] ?? 0);
        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');
        $encryption = static::normaliseEncryption($config['encryption'] ?? 'tls');

        /*
         * SHAPE VALIDATION RUNS FIRST - BEFORE THE "live check disabled" SHORT-CIRCUIT.
         *
         * The order matters and was previously wrong. The disabled-check branch used
         * to return `ok: true` immediately, which meant a request with a BLANK HOST
         * reported success whenever the suite (or any environment) had
         * SMTP_LIVE_CHECK=false. The UI would then show a green "connected" banner
         * for a configuration that could not possibly connect - the exact
         * false-confidence the check endpoint exists to prevent.
         *
         * Whether we can open a socket is a separate question from whether the
         * configuration is even complete. A missing host or an out-of-range port is
         * a SHAPE failure, and it must be reported as one in every environment.
         */
        if ($host === '') {
            return static::result(false, 'No SMTP host was provided.', $host, $port, $encryption);
        }

        if ($port < 1 || $port > 65535) {
            return static::result(false, 'The port must be between 1 and 65535.', $host, $port, $encryption);
        }

        /*
         * When the live check is disabled (the automated test suite), report a
         * successful "verified" result without opening a socket. The shape guards
         * above have already run, so a half-filled relay is still rejected.
         */
        if (! static::enabled()) {
            return static::result(
                true,
                "Connection check skipped for {$host}:{$port} (live checks are disabled in this environment).",
                $host,
                $port,
                $encryption
            );
        }

        // A blank password means "keep the stored secret" in the form. To test a
        // real connection we substitute the stored (decrypted) password so the
        // check reflects what would actually be used.
        if ($password === '') {
            $password = (string) (MailSettings::all()['password'] ?? '');
        }

        $scheme = match ($encryption) {
            'ssl' => 'smtps',   // implicit TLS (usually port 465)
            default => 'smtp',  // plain / STARTTLS negotiation (587 / 25)
        };

        try {
            $transport = static::buildTransport($host, $port, $username, $password, $scheme);

            // The handshake: connect + (START)TLS + AUTH. Throws on any failure.
            $transport->start();

            return static::result(
                true,
                "Connected successfully to {$host}:{$port} ({$encryption}).",
                $host,
                $port,
                $encryption,
                $transport
            );
        } catch (\Throwable $e) {
            return static::result(
                false,
                'Connection failed: '.static::readableError($e->getMessage()),
                $host,
                $port,
                $encryption
            );
        }
    }

    /**
     * Build the Symfony transport for the given connection values.
     *
     * Kept as its own method so a test can swap in a transport double, and so the
     * scheme/port rules live in exactly one place.
     */
    protected static function buildTransport(
        string $host,
        int $port,
        string $username,
        string $password,
        string $scheme
    ): TransportInterface {
        // `tls: true` = implicit TLS from the first byte (SMTPS / port 465).
        // `tls: false` = plain socket, letting the transport upgrade via STARTTLS
        // when the server advertises it (port 587 / 25).
        $transport = new EsmtpTransport(
            host: $host,
            port: $port,
            tls: $scheme === 'smtps',
        );

        // Credentials drive the AUTH LOGIN / AUTH PLAIN exchange during start().
        if ($username !== '') {
            $transport->setUsername($username);
            $transport->setPassword($password);
        }

        // Keep the handshake snappy so an unreachable host does not hang the UI.
        $stream = $transport->getStream();

        if (method_exists($stream, 'setTimeout')) {
            $stream->setTimeout(static::TIMEOUT);
        }

        return $transport;
    }

    /** Fold any UI value to one of our three canonical modes. */
    protected static function normaliseEncryption(?string $encryption): string
    {
        $encryption = strtolower(trim((string) $encryption));

        return in_array($encryption, ['tls', 'ssl', 'none'], true) ? $encryption : 'tls';
    }

    /**
     * Turn a transport exception into something a human can act on. The raw
     * Symfony/Socket messages can be long; we keep them but strip noise.
     */
    protected static function readableError(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        return $message !== '' ? $message : 'The mail server could not be reached.';
    }

    /** A consistent result shape for the controller + UI + tests. */
    protected static function result(
        bool $ok,
        string $message,
        ?string $host,
        int $port,
        string $encryption,
        ?TransportInterface $transport = null
    ): array {
        return [
            'ok' => $ok,
            'message' => $message,
            'driver' => $transport instanceof SmtpTransport ? 'smtp' : 'smtp',
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'transport' => $transport ? get_class($transport) : null,
        ];
    }
}
