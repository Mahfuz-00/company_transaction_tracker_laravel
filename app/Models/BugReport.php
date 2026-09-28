<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A user-raised bug report.
 *
 * DELIBERATELY NOT TENANT-SCOPED
 * ------------------------------
 * Every other domain model uses `BelongsToInstitution`, whose global scope hides
 * rows outside the active tenant. This one must NOT: the Software Super Admin
 * reviews reports from EVERY institution in one inbox, and a tenant-scoped model
 * would silently hide them. That is the same deliberate exception
 * `PlatformSetting` makes, and it is why access is gated at the ROUTE level
 * (`role:Software Super Admin`) rather than by a query scope.
 *
 * A tenant user never reads this table at all — they only ever WRITE a report
 * through `BugReportController::store`, which stamps their identity server-side.
 */
class BugReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'institution_id',
        'reporter_name',
        'reporter_email',
        'reporter_role',
        'page_url',
        'title',
        'description',
        'steps',
        'severity',
        'user_agent',
        'screenshot_path',
        'screenshot_name',
        'status',
        'resolved_by',
        'resolved_at',
        'resolution_notes',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ *
     * Enums — the single source of truth for the UI + validation
     * ------------------------------------------------------------------ */

    /** Severity, keyed by value with a label and the tone the chip uses. */
    public const SEVERITIES = [
        'low' => ['label' => 'Low', 'tone' => 'slate'],
        'normal' => ['label' => 'Normal', 'tone' => 'sky'],
        'high' => ['label' => 'High', 'tone' => 'amber'],
        'critical' => ['label' => 'Critical', 'tone' => 'rose'],
    ];

    /** Lifecycle of a report, keyed by value with a label and tone. */
    public const STATUSES = [
        'open' => ['label' => 'Open', 'tone' => 'rose'],
        'acknowledged' => ['label' => 'Acknowledged', 'tone' => 'amber'],
        'resolved' => ['label' => 'Resolved', 'tone' => 'emerald'],
        'dismissed' => ['label' => 'Dismissed', 'tone' => 'slate'],
    ];

    /** Severities that should surface at the very top of the SSA inbox. */
    public const URGENT_SEVERITIES = ['critical', 'high'];

    /* ------------------------------------------------------------------ *
     * Relations
     * ------------------------------------------------------------------ */

    public function reporter()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /* ------------------------------------------------------------------ *
     * Presentation helpers
     * ------------------------------------------------------------------ */

    public function severityLabel(): string
    {
        return self::SEVERITIES[$this->severity]['label'] ?? ucfirst((string) $this->severity);
    }

    public function severityTone(): string
    {
        return self::SEVERITIES[$this->severity]['tone'] ?? 'slate';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status]['label'] ?? ucfirst((string) $this->status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status]['tone'] ?? 'slate';
    }

    /** Is this report still awaiting action? */
    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'acknowledged'], true);
    }

    /** Does it deserve to be at the top of the queue? */
    public function isUrgent(): bool
    {
        return $this->isOpen() && in_array($this->severity, self::URGENT_SEVERITIES, true);
    }

    /** The public URL of the attached screenshot, or null when there is none. */
    public function screenshotUrl(): ?string
    {
        if (blank($this->screenshot_path)) {
            return null;
        }

        return Storage::disk('public')->url($this->screenshot_path);
    }
}
