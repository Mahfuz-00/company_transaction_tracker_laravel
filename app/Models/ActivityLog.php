<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One immutable audit entry. Written by App\Support\AuditLogger via model
 * observers, plus explicit calls for non-model events (login, invite sent).
 *
 * Rows are append-only by convention - nothing in the app updates them.
 */
class ActivityLog extends Model
{
    use BelongsToInstitution;
    use HasFactory;

    protected $fillable = [
        'user_id',
        'user_name',
        'user_email',
        'institution_id',
        'event',
        'description',
        'subject_type',
        'subject_id',
        'subject_label',
        'properties',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'properties' => 'array',
    ];

    /** Human labels + colour tone per event, used by the UI. */
    public const EVENTS = [
        'created' => ['label' => 'Created', 'tone' => 'emerald'],
        'updated' => ['label' => 'Updated', 'tone' => 'indigo'],
        'deleted' => ['label' => 'Deleted', 'tone' => 'rose'],
        'login' => ['label' => 'Signed in', 'tone' => 'sky'],
        'logout' => ['label' => 'Signed out', 'tone' => 'slate'],
        'invited' => ['label' => 'Invited', 'tone' => 'amber'],
        'accepted' => ['label' => 'Accepted invite', 'tone' => 'emerald'],
        'reversed' => ['label' => 'Reversed', 'tone' => 'rose'],
        'exported' => ['label' => 'Exported', 'tone' => 'violet'],
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    /** The model this row refers to, when it still exists. */
    public function subject()
    {
        return $this->morphTo();
    }

    /** Friendly module name, derived from the subject class. */
    public function getModuleAttribute(): string
    {
        if (! $this->subject_type) {
            return 'System';
        }

        return class_basename($this->subject_type);
    }

    public function getEventToneAttribute(): string
    {
        return self::EVENTS[$this->event]['tone'] ?? 'slate';
    }

    public function getEventLabelAttribute(): string
    {
        return self::EVENTS[$this->event]['label'] ?? ucfirst($this->event);
    }
}
