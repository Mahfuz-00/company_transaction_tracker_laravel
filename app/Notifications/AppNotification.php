<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A single, data-driven in-app notification.
 *
 * Rather than a class per event (claim.submitted, claim.approved, ...) we use
 * one class that carries a small payload. That keeps the DB rows uniform, the
 * React renderer simple (it switches on `kind`), and adding a new event is just
 * a new Notifier::send(...) call - no new class, no migration.
 *
 * The row is stored in the `notifications` table via the Notifiable trait, and
 * surfaced to the UI through NotificationController.
 */
class AppNotification extends Notification
{
    use Queueable;

    /**
     * @param  string  $kind  Machine key the UI switches on (claim_submitted, ...).
     * @param  string  $title  Short headline.
     * @param  string  $body  One-line detail.
     * @param  array  $meta  Extra context: url, amount, actor, claim_id, ...
     */
    public function __construct(
        public string $kind,
        public string $title,
        public string $body,
        public array $meta = [],
    ) {}

    /**
     * In-app only for now. Adding 'mail' here (guarded by an SMTP check) is the
     * single change needed to also email notifications once SMTP is available.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * The payload written to the notifications.data column.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => $this->body,
            'meta' => $this->meta,
        ];
    }
}
