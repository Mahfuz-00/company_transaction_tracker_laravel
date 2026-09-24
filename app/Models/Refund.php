<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A REFUND of a member's meal balance - money returned to the member.
 *
 * See the create_refunds_table migration for the design rationale. In short:
 * this is the debit side of a member's wallet (the mirror of Deposit), it is
 * never deleted (only reversed), and it carries `institution_id` so the tenant
 * scope keeps it isolated.
 *
 * `amount` is cast to `decimal:2` so it reads as an exact string; cast before
 * doing arithmetic (the same money rule that governs Deposit).
 */
class Refund extends Model
{
    use BelongsToInstitution;
    use HasFactory;

    protected $fillable = [
        'institution_id', 'student_id', 'amount', 'reason', 'payment_method',
        'recorded_by', 'transaction_id', 'notes',
        'reversed_at', 'reversed_by', 'reversal_transaction_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'reversed_at' => 'datetime',
    ];

    /** Why the balance was paid back. */
    public const REASONS = [
        'withdrawal' => 'Fund withdrawal',
        'stopped_meals' => 'Stopped meals',
        'settlement' => 'Balance settlement',
        'other' => 'Other',
    ];

    /** A reversed refund no longer reduces any balance. */
    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    /** Only refunds that have not been reversed. */
    public function scopeActive($query)
    {
        return $query->whereNull('reversed_at');
    }

    /** Only refunds that HAVE been reversed - the mirror of `active`. */
    public function scopeReversed($query)
    {
        return $query->whereNotNull('reversed_at');
    }

    /** The member this refund was paid to. */
    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    /** The user who processed the refund. */
    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** The administrator who reversed this refund. */
    public function reverser()
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    /** The cash-out ledger transaction posted for this refund. */
    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * The compensating cash-in posted when this refund was reversed (null while
     * the refund is still active).
     */
    public function reversalTransaction()
    {
        return $this->belongsTo(Transaction::class, 'reversal_transaction_id');
    }
}
