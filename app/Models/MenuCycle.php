<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * A repeating MENU CYCLE (e.g. a 7-day rotation) for one institution.
 *
 * The cycle drives procurement forecasts: expected eaters × servings × ingredient
 * quantities = what to buy and what it should cost.
 */
class MenuCycle extends Model
{
    use BelongsToInstitution;

    protected $fillable = [
        'institution_id',
        'name',
        'description',
        'cycle_length',
        'starts_on',
        'is_active',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'is_active' => 'boolean',
    ];

    public function days()
    {
        return $this->hasMany(MenuCycleDay::class)->orderBy('day_number');
    }

    public function ingredients()
    {
        return $this->hasMany(MenuIngredient::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Which day of the cycle a calendar date falls on (1..cycle_length).
     *
     * Returns null when the cycle has no start date or the date precedes it, so a
     * caller never silently plans for the wrong day.
     */
    public function dayNumberFor(\DateTimeInterface|string $date): ?int
    {
        if (! $this->starts_on || $this->cycle_length < 1) {
            return null;
        }

        $target = Carbon::parse($date)->startOfDay();
        $start = $this->starts_on->copy()->startOfDay();

        if ($target->lt($start)) {
            return null;
        }

        $offset = $start->diffInDays($target);

        return ($offset % $this->cycle_length) + 1;
    }
}
