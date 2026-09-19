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
 * The Institution Welcome & Setup email.
 *
 * Dispatched automatically the moment the Software Super Admin provisions a new
 * workspace - whether it was created on a 7-day trial or an immediate permanent
 * subscription. The copy reflects the actual billing state so the admin is never
 * surprised about what they signed up for.
 */
class InstitutionWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Institution $institution,
        public User $admin,
        // 'trial' | 'subscription' - drives the copy + the call-to-action.
        public string $mode = 'subscription',
        // The temporary password the admin can sign in with immediately.
        public ?string $temporaryPassword = null,
        // A signed, single-use link the admin can use to set their OWN password.
        public ?string $setupUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to '.config('app.name', 'the platform').' - your workspace is ready',
            tags: ['institution_welcome'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.institution-welcome',
            with: [
                'institutionName' => $this->institution->name,
                'adminName' => $this->admin->name,
                'adminEmail' => $this->admin->email,
                'loginUrl' => route('login'),
                'accent' => $this->institution->accentPalette()['hex'] ?? '#4f46e5',
                'subject' => $this->envelope()->subject,
                'mode' => $this->mode,
                'trialDays' => Institution::TRIAL_DAYS,
                'trialEndsAt' => $this->institution->trial_ends_at,
                'typeLabel' => $this->institution->typeLabel(),
                // The SSA provisions the FIRST admin with a password chosen at
                // creation, so they can sign in immediately.
                'isSubscribed' => $this->mode !== 'trial',
                // Credentials block: temporary password + signed setup link, so
                // the admin is NEVER locked out waiting for credentials.
                'temporaryPassword' => $this->temporaryPassword,
                'setupUrl' => $this->setupUrl,
            ],
        );
    }
}
