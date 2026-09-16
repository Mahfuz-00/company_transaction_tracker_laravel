<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-institution configuration for how the meal rate is worked out.
 *
 * The core formula the whole system revolves around is:
 *
 *     per-meal rate = total expense / total meals
 *
 * `rate_mode` decides whether that calculation is used as-is ('calculated'),
 * replaced by a stored figure ('manual'), or used only as a floor under a
 * manual value ('hybrid'). `target_subsidy_ratio` is the share of that cost
 * the institution expects subsidies to cover (e.g. 20 => the 80/20 rule).
 */
class MealRateSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'rate_mode',
        'target_subsidy_ratio',
        'manual_rate',
        'carry_forward',
    ];

    protected $casts = [
        'target_subsidy_ratio' => 'decimal:2',
        'manual_rate' => 'decimal:4',
        'carry_forward' => 'boolean',
    ];

    public const RATE_MODES = [
        'calculated' => 'Calculated (expense ÷ meals)',
        'manual' => 'Manual fixed rate',
        'hybrid' => 'Hybrid (manual floor, calculated ceiling)',
    ];

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * The settings row for the active institution, created on first use so
     * callers never have to null-check.
     */
    public static function current(): static
    {
        $institution = Institution::current();

        return static::firstOrCreate(
            ['institution_id' => $institution?->id],
            ['rate_mode' => 'calculated', 'target_subsidy_ratio' => 20, 'carry_forward' => true]
        );
    }

    /**
     * Resolve the effective per-meal rate from the live figures, honouring the
     * configured mode.
     *
     * @param  float  $totalExpense  money spent in the period
     * @param  int  $totalMeals  meals eaten in the period
     */
    public function resolveRate(float $totalExpense, int $totalMeals): float
    {
        $calculated = $totalMeals > 0 ? round($totalExpense / $totalMeals, 4) : 0.0;

        return match ($this->rate_mode) {
            'manual' => (float) ($this->manual_rate ?? $calculated),
            'hybrid' => max((float) ($this->manual_rate ?? 0), $calculated),
            default => $calculated,
        };
    }
}
