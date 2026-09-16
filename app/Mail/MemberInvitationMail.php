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
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You are invited to '.($this->institutionName ?: config('app.name')),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.member-invitation',
            with: [
                'name' => $this->invitation->name,
                'email' => $this->invitation->email,
                'acceptUrl' => $this->acceptUrl,
                'expiresAt' => $this->invitation->expires_at,
                'institutionName' => $this->institutionName,
                'role' => $this->invitation->role,
            ],
        );
    }
}
