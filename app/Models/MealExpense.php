<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A meal-related outgoing cost (vendor payments, utilities, supplies, ...).
 *
 * MONEY HANDLING
 * --------------
 * `amount` is cast to `decimal:2`, so it reads back as a fixed 2-decimal STRING
 * rather than a float. Binary floats cannot represent money exactly, so the
 * value is kept exact and only turned into a number after an explicit cast or
 * when formatted with App\Support\Money.
 *
 * MULTI-TENANCY
 * -------------
 * `use BelongsToInstitution` gives the model the automatic tenant scope and
 * create-time `institution_id` stamp described on that trait: an expense is
 * always confined to the active institution and can never leak across tenants.
 *
 * REVERSAL
 * --------
 * Like deposits, expenses are reversed rather than deleted. `reversed_at` /
 * `reversed_by` record who undid it and `reversal_transaction_id` links the
 * compensating transaction; the `active` scope then excludes it from totals.
 */
class MealExpense extends Model
{
    use BelongsToInstitution;
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'transaction_id',
        'vendor_id',
        'description',
        'category',
        'amount',
        'payment_status',
        'recorded_by',
        'reversed_at',
        'reversed_by',
        'reversal_transaction_id',
    ];

    /**
     * `decimal:2` returns the amount as a fixed 2-decimal string; `reversed_at`
     * becomes a Carbon instance (null while the expense is active).
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'reversed_at' => 'datetime',
    ];

    /** A reversed expense no longer counts toward any total. */
    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    /** Only expenses that have not been reversed. */
    public function scopeActive($query)
    {
        return $query->whereNull('reversed_at');
    }

    /** The ledger transaction recorded for this expense. */
    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    /** The administrator who reversed this expense. */
    public function reverser()
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    /**
     * The compensating ledger transaction written when the expense was reversed
     * (null while the expense is still active).
     */
    public function reversalTransaction()
    {
        return $this->belongsTo(Transaction::class, 'reversal_transaction_id');
    }

    /** The vendor paid by this expense. */
    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    /** The user who recorded the expense. */
    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
