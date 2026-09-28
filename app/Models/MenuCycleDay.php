<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One day within a menu cycle. `dishes` is a JSON array of what is served, e.g.
 *
 *   [
 *     { "meal": "lunch", "dish": "Rice + Chicken", "servings": 1 },
 *     { "meal": "dinner", "dish": "Khichuri", "servings": 1 }
 *   ]
 *
 * Stored as JSON because a day's menu is edited as a whole unit and is never
 * queried by an individual dish.
 */
class MenuCycleDay extends Model
{
    protected $fillable = [
        'menu_cycle_id',
        'day_number',
        'label',
        'dishes',
    ];

    protected $casts = [
        'dishes' => 'array',
    ];

    public function cycle()
    {
        return $this->belongsTo(MenuCycle::class, 'menu_cycle_id');
    }

    /** The dishes as a flat list, tolerating a null/blank JSON value. */
    public function dishList(): array
    {
        $dishes = $this->dishes;

        return is_array($dishes) ? $dishes : [];
    }
}
