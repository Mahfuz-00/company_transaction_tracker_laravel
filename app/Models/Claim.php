<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A member claim or dispute.
 *
 * Two kinds share this model:
 *   - 'dispute' : a missing deposit or meal entry the member is contesting.
 *   - 'expense' : money the member personally spent on the institution's behalf.
 *
 * Nothing here moves money; approval (in ClaimController) is what creates the
 * deposit / transaction. The row itself is the request + its full audit trail.
 */
class Claim extends Model
{
    use BelongsToInstitution;
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'student_id',
        'kind',
        'subject',
        'amount',
        'entry_date',
        'breakfast',
        'lunch',
        'dinner',
        'title',
        'description',
        'claim_date',
        'payment_method',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'result_deposit_id',
        'result_transaction_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'entry_date' => 'date',
        'claim_date' => 'date',
        'reviewed_at' => 'datetime',
        'breakfast' => 'integer',
        'lunch' => 'integer',
        'dinner' => 'integer',
    ];

    /** Claim kinds, keyed by value with a friendly label + UI tone. */
    public const KINDS = [
        'dispute' => ['label' => 'Missing entry / dispute', 'tone' => 'amber'],
        'expense' => ['label' => 'I bought something', 'tone' => 'sky'],
    ];

    /** What a deposit/meal dispute can be about. */
    public const SUBJECTS = [
        'deposit' => 'Missing deposit',
        'meal' => 'Missing meal entry',
        'other' => 'Other correction',
    ];

    /** Lifecycle. */
    public const STATUSES = [
        'pending' => ['label' => 'Pending review', 'tone' => 'amber'],
        'approved' => ['label' => 'Approved', 'tone' => 'emerald'],
        'rejected' => ['label' => 'Rejected', 'tone' => 'rose'],
    ];

    /* ------------------------------------------------------------------ *
     * Relationships
     * ------------------------------------------------------------------ */

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    /** The manager who approved or rejected this claim. */
    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Set when an approved deposit dispute created a deposit row. */
    public function resultDeposit()
    {
        return $this->belongsTo(Deposit::class, 'result_deposit_id');
    }

    /** Set when an approved claim produced a ledger transaction. */
    public function resultTransaction()
    {
        return $this->belongsTo(Transaction::class, 'result_transaction_id');
    }

    /* ------------------------------------------------------------------ *
     * Scopes
     * ------------------------------------------------------------------ */

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeForInstitution($query, ?int $institutionId)
    {
        return $query->where('institution_id', $institutionId);
    }

    public function scopeOfKind($query, string $kind)
    {
        return $query->where('kind', $kind);
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind]['label'] ?? ucfirst($this->kind);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status]['label'] ?? ucfirst($this->status);
    }

    /** "3 meals on 12 Sep" / "1,200 missing deposit" - a compact summary. */
    public function getSummaryAttribute(): string
    {
        if ($this->kind === 'dispute' && $this->subject === 'meal') {
            $meals = (int) ($this->breakfast ?? 0)
                + (int) ($this->lunch ?? 0)
                + (int) ($this->dinner ?? 0);

            return $meals . ' meal' . ($meals === 1 ? '' : 's')
                . ($this->entry_date ? ' on ' . $this->entry_date->format('j M Y') : '');
        }

        return $this->title;
    }
}
