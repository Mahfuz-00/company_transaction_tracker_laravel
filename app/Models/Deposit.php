<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single top-up of a member's meal wallet — the credit side of the ledger.
 *
 * MONEY HANDLING
 * --------------
 * `amount` is cast to `decimal:2`, so reading it yields a STRING such as
 * "1500.00" rather than a float. That is deliberate: binary floats cannot hold
 * money exactly (0.1 + 0.2 !== 0.3), so the value stays exact until it is
 * formatted through App\Support\Money or summed inside the database. Treat the
 * string as a number only after an explicit cast.
 *
 * MULTI-TENANCY
 * -------------
 * `use BelongsToInstitution` adds the automatic tenant behaviour described on
 * that trait: a global scope filters every query to the active institution's
 * `institution_id`, and a new row is stamped with it on create. A deposit can
 * therefore never be read or written across tenants by accident.
 *
 * KINDS
 * -----
 * `self::KINDS` enumerates the three deposit flavours. Only `personal` is real
 * member money; `subsidy` is tracked apart so balance rules stay strict, and
 * `credit` covers manual adjustments. The `personal` scope narrows to the first.
 *
 * REVERSAL, NOT DELETION
 * ----------------------
 * A deposit is never deleted. Reversing it stamps `reversed_at` / `reversed_by`
 * and links a compensating `reversal_transaction_id`; afterwards `isReversed()`
 * is true and the `active` scope hides it from balances. This keeps the finance
 * audit trail whole.
 */
class Deposit extends Model
{
    use BelongsToInstitution;
    use HasFactory;

    protected $fillable = [
        'institution_id', 'student_id', 'amount', 'kind', 'subsidy_id',
        'payment_method', 'recorded_by', 'transaction_id', 'notes',
        'reversed_at', 'reversed_by', 'reversal_transaction_id',
    ];

    /**
     * `decimal:2` returns the amount as a fixed 2-decimal string; `reversed_at`
     * becomes a Carbon instance (null while the deposit is active).
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'reversed_at' => 'datetime',
    ];

    /** personal | subsidy | credit */
    public const KINDS = [
        'personal' => 'Personal deposit',
        'subsidy' => 'Institutional subsidy',
        'credit' => 'Credit adjustment',
    ];

    /** A reversed deposit no longer counts toward any balance. */
    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    /** Only deposits that have not been reversed. */
    public function scopeActive($query)
    {
        return $query->whereNull('reversed_at');
    }

    /** Only deposits that HAVE been reversed — the mirror of `active`. */
    public function scopeReversed($query)
    {
        return $query->whereNotNull('reversed_at');
    }

    /**
     * The member this deposit belongs to.
     *
     * A `belongsTo` is the inverse of a `hasMany`: this row carries a
     * `student_id` foreign key and resolves to the single owning Student.
     */
    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    /** The administrator who reversed this deposit. */
    public function reverser()
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    /**
     * The compensating ledger transaction written when the deposit was reversed
     * (null while the deposit is still active).
     */
    public function reversalTransaction()
    {
        return $this->belongsTo(Transaction::class, 'reversal_transaction_id');
    }

    /** The subsidy that funded this deposit, when `kind` is `subsidy`. */
    public function subsidy()
    {
        return $this->belongsTo(Subsidy::class);
    }

    /**
     * Only genuine personal deposits count toward a member's own funds. Subsidy
     * money is tracked separately so balance rules stay strict.
     */
    public function scopePersonal($query)
    {
        return $query->where('kind', 'personal');
    }

    /** The ledger transaction recorded for this deposit. */
    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    /** The user who recorded the deposit. */
    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
