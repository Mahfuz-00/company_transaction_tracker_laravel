<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One dispatched email in the outbox.
 *
 * Rows are written by the LogSentEmail listener on Laravel's MessageSent event,
 * so NO controller has to remember to log - every email is captured centrally.
 */
class EmailLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'user_id',
        'to',
        'from',
        'cc',
        'bcc',
        'subject',
        'kind',
        'mailable',
        'status',
        'error',
        'body',
        'text_body',
        'message_id',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    /** Status labels + UI tone. */
    public const STATUSES = [
        'sent' => ['label' => 'Sent', 'tone' => 'emerald'],
        'pending' => ['label' => 'Pending', 'tone' => 'amber'],
        'failed' => ['label' => 'Failed', 'tone' => 'rose'],
    ];

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    /** The user who triggered the send, when known. */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForInstitution($query, ?int $institutionId)
    {
        return $query->where('institution_id', $institutionId);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status]['label'] ?? ucfirst($this->status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status]['tone'] ?? 'slate';
    }
}
