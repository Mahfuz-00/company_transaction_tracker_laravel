<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;

/**
 * An ingredient line driving the procurement forecast.
 *
 * `qty_per_serving` is the amount needed for ONE serving of the linked dish, so
 * the forecast is simply: expected servings × qty_per_serving = quantity to buy.
 */
class MenuIngredient extends Model
{
    use BelongsToInstitution;

    protected $fillable = [
        'institution_id',
        'menu_cycle_id',
        'dish',
        'name',
        'unit',
        'qty_per_serving',
        'unit_cost',
        'vendor_id',
    ];

    protected $casts = [
        'qty_per_serving' => 'decimal:4',
        'unit_cost' => 'decimal:4',
    ];

    public function cycle()
    {
        return $this->belongsTo(MenuCycle::class, 'menu_cycle_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    /** Cost of the given quantity of this ingredient. */
    public function costFor(float $quantity): float
    {
        return round($quantity * (float) $this->unit_cost, 2);
    }
}
