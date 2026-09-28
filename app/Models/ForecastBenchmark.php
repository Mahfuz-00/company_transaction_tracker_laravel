<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A COUNTRY-LEVEL FORECAST BENCHMARK.
 *
 * Used as the fallback when an institution has too little history to retrieve from
 * (< 3 months). Instead of inventing a number, the forecaster anchors to a
 * published aggregate for that country - e.g. Bangladesh dormitory meal costs - and
 * clearly labels the estimate as benchmark-derived.
 *
 * Not tenant-scoped: a benchmark describes a country, not a workspace.
 */
class ForecastBenchmark extends Model
{
    protected $fillable = [
        'country_code',
        'metric',
        'period_month',
        'value',
        'unit',
        'source',
        'notes',
    ];

    protected $casts = [
        'period_month' => 'date',
        'value' => 'decimal:4',
    ];

    /** Metric keys the forecaster understands. */
    public const METRICS = [
        'cost_per_meal' => 'Average cost per meal',
        'meals_per_member' => 'Meals eaten per member per day',
        'daily_meal_rate' => 'Share of members eating on a given day',
        'subsidy_pct' => 'Typical institutional subsidy share',
        'meal_price' => 'Chargeable meal price',
    ];

    /**
     * The most recent benchmark for a metric in a country, on or before a date.
     *
     * Returns null when nothing is published, so a caller must decide how to
     * degrade rather than silently treating a missing benchmark as zero.
     */
    public static function latest(string $countryCode, string $metric, ?string $on = null): ?self
    {
        return static::query()
            ->where('country_code', strtoupper($countryCode))
            ->where('metric', $metric)
            ->when($on !== null, fn ($q) => $q->where('period_month', '<=', $on))
            ->orderByDesc('period_month')
            ->first();
    }
}
