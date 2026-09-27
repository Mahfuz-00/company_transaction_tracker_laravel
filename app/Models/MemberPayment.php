<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A member-initiated payment intent.
 *
 * SAFETY MODEL: creating one of these does NOT move any money. It sits in
 * `pending` until a manager (or a gateway callback) approves it, at which point a
 * real Deposit is created and `deposit_id` links back to it. That separation is
 * what stops a member crediting their own account.
 */
class MemberPayment extends Model
{
    use BelongsToInstitution;

    protected $fillable = [
        'institution_id',
        'student_id',
        'user_id',
        'reference',
        'amount',
        'method',
        'status',
        'payer_reference',
        'note',
        'deposit_id',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'reviewed_at' => 'datetime',
    ];

    /** Methods a member may pick from, with UI metadata. */
    public const METHODS = [
        'cash' => ['label' => 'Cash (hand to your manager)'],
        'bkash' => ['label' => 'bKash'],
        'nagad' => ['label' => 'Nagad'],
        'bank' => ['label' => 'Bank transfer'],
        'card' => ['label' => 'Card'],
    ];

    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function deposit()
    {
        return $this->belongsTo(Deposit::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method]['label'] ?? ucfirst((string) $this->method);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            'approved' => 'emerald',
            'rejected' => 'rose',
            'cancelled' => 'slate',
            default => 'amber',
        };
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /** A short, unique, member-facing reference. */
    public static function generateReference(): string
    {
        do {
            $reference = 'MP-'.strtoupper(Str::random(8));
        } while (static::withoutTenantScope()->where('reference', $reference)->exists());

        return $reference;
    }
}
