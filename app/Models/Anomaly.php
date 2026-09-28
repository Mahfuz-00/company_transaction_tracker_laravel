<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;

/**
 * A detected financial or operational anomaly, awaiting review.
 *
 * Rows are produced by App\Support\AnomalyDetector and are IDEMPOTENT: the unique
 * `fingerprint` means re-running the scan updates an existing finding instead of
 * creating a duplicate.
 */
class Anomaly extends Model
{
    use BelongsToInstitution;

    protected $fillable = [
        'institution_id',
        'kind',
        'severity',
        'subject_type',
        'subject_id',
        'student_id',
        'title',
        'detail',
        'amount',
        'score',
        'detected_for',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'fingerprint',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'score' => 'decimal:4',
        'detected_for' => 'date',
        'reviewed_at' => 'datetime',
    ];

    /** Human labels for each kind, used by the monitor UI. */
    public const KINDS = [
        'duplicate_deposit' => 'Duplicate deposit',
        'meal_spike' => 'Unusual meal spike',
        'negative_balance' => 'Negative balance',
        'unusual_expense' => 'Unusual expense',
        'dormant_reactivation' => 'Dormant account reactivated',
    ];

    public const SEVERITIES = ['info', 'warning', 'critical'];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? ucfirst(str_replace('_', ' ', (string) $this->kind));
    }

    /** Severity -> a Tailwind tone the UI maps to colours. */
    public function severityTone(): string
    {
        return match ($this->severity) {
            'critical' => 'rose',
            'warning' => 'amber',
            default => 'slate',
        };
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }
}
