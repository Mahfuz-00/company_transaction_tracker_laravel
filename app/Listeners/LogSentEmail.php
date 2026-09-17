<?php

namespace App\Listeners;

use App\Models\EmailLog;
use App\Models\Institution;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Auth;

/**
 * Records every dispatched email into the outbox.
 *
 * Laravel fires MessageSent for any mail sent through the Mailer (Mailables and
 * Notifications alike), so this single listener gives us a complete log without
 * touching a single controller. The full rendered HTML body is available on the
 * Swift/Symfony message, which is exactly what we persist for the "view email"
 * preview.
 */
class LogSentEmail
{
    public function handle(MessageSent $event): void
    {
        try {
            $message = $event->message;

            // Symfony Email: headers carry envelope details.
            $to = $this->addresses($message->getTo());
            $cc = $this->addresses($message->getCc());
            $bcc = $this->addresses($message->getBcc());
            $from = $this->addresses($message->getFrom());

            // Prefer the HTML body; fall back to plain text.
            [$html, $text] = $this->bodies($message);

            // A readable kind, derived from the Mailable/Notification class the
            // payload carries (set by our Mailables), else guessed from subject.
            $kind = $event->data['__kind']
                ?? $this->guessKind($message->getHeaders()->get('X-Email-Kind')?->getBodyAsString(), $html);

            $mailable = $event->data['__laravel_mailable'] ?? null;

            EmailLog::create([
                'institution_id' => $this->resolveInstitutionId(),
                'user_id' => Auth::id(),
                'to' => $to,
                'from' => $from,
                'cc' => $cc,
                'bcc' => $bcc,
                'subject' => $message->getSubject(),
                'kind' => $kind,
                'mailable' => is_string($mailable) ? class_basename($mailable) : null,
                'status' => 'sent',
                'body' => $html,
                'text_body' => $text,
                'message_id' => $message->getHeaders()->get('X-Message-ID')?->getBodyAsString(),
                'sent_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Logging must never break a send, but we DO want the reason captured
            // for diagnosis rather than silently lost.
            try {
                EmailLog::create([
                    'to' => $this->safeAddresses($event->message->getTo()),
                    'subject' => $event->message->getSubject() ?: '(untitled)',
                    'kind' => 'general',
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                    'body' => null,
                    'sent_at' => now(),
                ]);
            } catch (\Throwable $inner) {
                // If even the failure row cannot be written, fall back to the log.
                report($e);
            }
        }
    }

    /** Address extractor that can never throw (used on the error path). */
    protected function safeAddresses(?array $list): ?string
    {
        try {
            return $this->addresses($list);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Flatten a header address list into "Name <email>, ...".
     *
     * Symfony Mailer returns Symfony\Component\Mime\Address objects (not the
     * [email, name] arrays the older Swift mailer used), so both shapes are
     * handled defensively.
     */
    protected function addresses(?array $list): ?string
    {
        if (empty($list)) {
            return null;
        }

        return collect($list)
            ->map(function ($a) {
                // Symfony\Mime\Address object.
                if (is_object($a) && method_exists($a, 'getAddress')) {
                    $name = $a->getName();

                    return $name ? "{$name} <{$a->getAddress()}>" : $a->getAddress();
                }

                // Legacy [email, name] array shape.
                if (is_array($a)) {
                    return isset($a[1]) && $a[1] ? "{$a[1]} <{$a[0]}>" : $a[0];
                }

                return (string) $a;
            })
            ->implode(', ');
    }

    /**
     * Pull the HTML + text bodies from the Symfony Email.
     *
     * Symfony\Component\Mime\Email exposes getHtmlBody() / getTextBody()
     * directly - there is no isMultipart() on this class (that was the older
     * Swift Mailer API). Multipart parts are handled by those accessors.
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected function bodies($message): array
    {
        $html = null;
        $text = null;

        if (method_exists($message, 'getHtmlBody')) {
            $html = $message->getHtmlBody();
        }

        if (method_exists($message, 'getTextBody')) {
            $text = $message->getTextBody();
        }

        // Some mailers deliver the body as a single raw part instead.
        if ($html === null && $text === null && method_exists($message, 'getBody')) {
            $body = $message->getBody();
            if ($body && method_exists($body, 'getBody')) {
                if (method_exists($body, 'getMediaSubtype') && $body->getMediaSubtype() === 'html') {
                    $html = $body->getBody();
                } else {
                    $text = $body->getBody();
                }
            }
        }

        return [$html, $text];
    }

    /** Best-effort kind from an explicit header, else the subject line. */
    protected function guessKind(?string $header, ?string $html): string
    {
        if ($header) {
            return $header;
        }

        $haystack = strtolower($html ?? '');

        return match (true) {
            str_contains($haystack, 'invited') || str_contains($haystack, 'set my password') => 'invitation',
            str_contains($haystack, 'password') && str_contains($haystack, 'reset') => 'password_reset',
            str_contains($haystack, 'announcement') => 'announcement',
            default => 'general',
        };
    }

    /** The active institution, so the log is scoped per workspace. */
    protected function resolveInstitutionId(): ?int
    {
        return Auth::user()?->institution_id ?? Institution::current()?->id;
    }
}
