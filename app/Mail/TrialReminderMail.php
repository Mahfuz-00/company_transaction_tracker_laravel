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
 * Trial Expiration & Upgrade Reminder email.
 *
 * Sent as an institution's 7-day trial nears its end (and again when it has
 * lapsed), inviting the Institution Admin to move onto a permanent
 * subscription. Used by both the automated reminder command and the SSA's
 * manual "Send upgrade prompt" action.
 */
class TrialReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Institution $institution,
        public User $admin,
        // 'ending' (still inside the window) | 'expired' (window closed).
        public string $state = 'ending',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->state === 'expired'
                ? 'Your free trial has ended - '.$this->institution->name
                : 'Your free trial ends soon - '.$this->institution->name,
            tags: ['trial_reminder', $this->state],
        );
    }

    public function content(): Content
    {
        $daysLeft = $this->institution->trialDaysLeft();

        return new Content(
            view: 'mail.trial-reminder',
            with: [
                'institutionName' => $this->institution->name,
                'adminName' => $this->admin->name,
                'planUrl' => route('settings.institutions.index'),
                'accent' => $this->institution->accentPalette()['hex'] ?? '#4f46e5',
                'subject' => $this->envelope()->subject,
                'state' => $this->state,
                'daysLeft' => $daysLeft,
                'trialEndsAt' => $this->institution->trial_ends_at,
            ],
        );
    }
}
