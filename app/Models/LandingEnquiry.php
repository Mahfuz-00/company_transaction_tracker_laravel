<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A landing-page demo request / enquiry.
 *
 * Platform-level (no tenant scope) - it exists before an institution does. The
 * SSA reviews it and can approve it, which provisions an institution from the
 * enquiry's details.
 */
class LandingEnquiry extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'institution_name',
        'institution_type',
        'message',
        'status',
        'institution_id',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    /** The institution provisioned from this enquiry, if any. */
    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    /** The SSA who reviewed it. */
    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public const STATUSES = [
        'new' => ['label' => 'New', 'tone' => 'sky'],
        'contacted' => ['label' => 'Contacted', 'tone' => 'amber'],
        'approved' => ['label' => 'Approved', 'tone' => 'emerald'],
        'rejected' => ['label' => 'Rejected', 'tone' => 'slate'],
    ];

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status]['label'] ?? ucfirst((string) $this->status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status]['tone'] ?? 'slate';
    }

    public function isNew(): bool
    {
        return $this->status === 'new';
    }
}
