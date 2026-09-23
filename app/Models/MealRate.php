<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A per-meal price over a date range — the "cost per head" the billing engine
 * multiplies by each member's meal counts to produce a charge.
 *
 * Rate rows are keyed by `from_date` / `to_date` so a historic charge always
 * recomputes against the rate that was in force on that day; raising the rate
 * later never rewrites past bills.
 *
 * MONEY HANDLING
 * --------------
 * `cost_per_meal` is stored to four decimals (`decimal:4`) because dividing a
 * total cost by a meal count rarely lands on a whole cent; `total_cost` keeps
 * the usual two (`decimal:2`). Both come back as exact decimal STRINGS, not
 * floats.
 *
 * TENANCY NOTE
 * ------------
 * This model intentionally does NOT use BelongsToInstitution and has no
 * `institution_id` column: a rate row is not tenant-scoped.
 */
class MealRate extends Model
{
    use HasFactory;

    protected $fillable = ['from_date', 'to_date', 'total_cost', 'cost_per_meal', 'created_by'];

    /**
     * The date columns become Carbon instances for range comparisons;
     * `total_cost` keeps 2 decimals (money) while `cost_per_meal` keeps 4 so a
     * rounded unit price does not distort the totals it is later multiplied by.
     */
    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
        'total_cost' => 'decimal:2',
        'cost_per_meal' => 'decimal:4',
    ];

    /** The user who created this rate row. */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
