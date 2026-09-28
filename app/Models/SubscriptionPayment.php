<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A SUBSCRIPTION PAYMENT from an institution to the platform.
 *
 * The safety model deliberately mirrors MemberPayment: an Institution Admin
 * SUBMITS a payment (status `pending`) and the Software Super Admin VERIFIES it.
 * Until then the institution's subscription is untouched, so an unverified claim
 * cannot silently extend access to the platform.
 */
class SubscriptionPayment extends Model
{
    use BelongsToInstitution;

    protected $fillable = [
        'institution_id',
        'reference',
        'plan_name',
        'amount',
        'currency_code',
        'period_months',
        'method',
        'status',
        'paid_on',
        'payer_reference',
        'note',
        'covers_from',
        'covers_to',
        'submitted_by',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_on' => 'date',
        'covers_from' => 'date',
        'covers_to' => 'date',
        'reviewed_at' => 'datetime',
    ];

    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    public const METHODS = [
        'bank' => 'Bank transfer',
        'card' => 'Card',
        'bkash' => 'bKash',
        'nagad' => 'Nagad',
        'wire' => 'Wire transfer',
        'other' => 'Other',
    ];

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
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

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? ucfirst((string) $this->method);
    }

    /**
     * A unique, human-facing reference that doubles as the idempotency key.
     *
     * A colliding reference would let a retried submission be treated as a new
     * payment, so uniqueness is checked against the GLOBAL table (withoutTenantScope)
     * rather than just the current institution.
     */
    public static function generateReference(): string
    {
        do {
            $reference = 'SUB-'.now()->format('ymd').'-'.strtoupper(Str::random(5));
        } while (static::withoutTenantScope()->where('reference', $reference)->exists());

        return $reference;
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /** Is this payment still awaiting verification? */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /**
     * RESOLVE THE MODEL FOR ROUTE BINDING WITHOUT THE TENANT SCOPE.
     *
     * A subscription payment is a PLATFORM-level record: the Software Super Admin
     * verifies it while acting GLOBALLY, with no active tenant. The
     * BelongsToInstitution global scope would therefore hide the very row the SSA
     * is trying to approve - a 404 on a payment they can plainly see in the queue.
     *
     * Authorization is NOT weakened by this: every controller action that uses the
     * binding first asserts `isSuperAdmin()` (see
     * SubscriptionPaymentController::authorisePlatform), and the institution-scoped
     * billing page filters by institution explicitly rather than relying on the
     * binding.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return static::withoutTenantScope()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }
}
