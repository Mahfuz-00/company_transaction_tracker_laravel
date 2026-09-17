<?php

namespace App\Mail;

use App\Models\MemberInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The secure invitation email. Carries a signed URL the recipient follows to
 * set their own password and finish creating their account.
 */
class MemberInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public MemberInvitation $invitation,
        public string $acceptUrl,
        public ?string $institutionName = null,
        // true when re-issuing for an already-existing account (password reset),
        // false for a first-time invitation. Only the copy changes.
        public bool $isReset = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->isReset
                ? 'Reset your password'.($this->institutionName ? ' - '.$this->institutionName : '')
                : 'You are invited to '.($this->institutionName ?: config('app.name')),
            // Surfaced to the outbox listener so rows are grouped by kind.
            tags: [$this->isReset ? 'password_reset' : 'invitation'],
        );
    }

    public function content(): Content
    {
        return new Content(
            // Custom Tailwind-authored HTML template (not the Markdown default),
            // so the email matches the app's design system.
            view: 'mail.member-invitation',
            with: [
                'name' => $this->invitation->name,
                'email' => $this->invitation->email,
                'acceptUrl' => $this->acceptUrl,
                'expiresAt' => $this->invitation->expires_at,
                'institutionName' => $this->institutionName,
                'role' => $this->invitation->role,
                'isReset' => $this->isReset,
                'accent' => $this->accent(),
                'subject' => $this->envelope()->subject,
            ],
        );
    }

    /** The active institution's accent, so the email matches the workspace. */
    protected function accent(): string
    {
        return \App\Models\Institution::current()?->accentPalette()['hex'] ?? '#4f46e5';
    }
}
