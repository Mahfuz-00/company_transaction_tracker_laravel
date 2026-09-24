<?php

namespace App\Mail;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Member Welcome & Account Activation email.
 *
 * Dispatched automatically right after a member follows their invitation link,
 * sets a password and completes registration. It confirms the account is live
 * and tells them what they can now do - scoped to their institution.
 */
class MemberWelcomeMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public User $user,
        public ?Institution $institution = null,
    ) {
    }

    public function envelope(): Envelope
    {
        $brand = $this->institution?->name ?: \App\Support\PlatformBranding::name();

        return new Envelope(
            subject: 'Welcome aboard - your ' . $brand . ' account is active',
            tags: ['member_welcome'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.member-welcome',
            with: [
                'name' => $this->user->name,
                'email' => $this->user->email,
                'institutionName' => $this->institution?->name,
                'loginUrl' => route('login'),
                'accent' => $this->institution?->accentPalette()['hex'] ?? '#4f46e5',
                'subject' => $this->envelope()->subject,
                // Terminology-aware labels so a company says "Contributions"
                // rather than "Deposits" - the member sees the same words as the
                // rest of their workspace.
                'terms' => $this->institution?->terminologyMap() ?? [],
            ],
        );
    }
}
