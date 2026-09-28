<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ONE DISH an eligible member can vote for on a menu.
 *
 * `estimated_cost` is per serving, which lets the winning option drive a
 * procurement estimate without re-entering prices.
 */
class MealMenuOption extends Model
{
    protected $fillable = [
        'meal_menu_id',
        'name',
        'description',
        'estimated_cost',
        'is_recommended',
        'sort_order',
    ];

    protected $casts = [
        'estimated_cost' => 'decimal:2',
        'is_recommended' => 'boolean',
    ];

    public function menu()
    {
        return $this->belongsTo(MealMenu::class, 'meal_menu_id');
    }

    public function votes()
    {
        return $this->hasMany(MealMenuVote::class);
    }

    /** How many members chose this option. */
    public function voteCount(): int
    {
        return $this->votes()->count();
    }
}
