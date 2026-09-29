<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;

/**
 * A TRAINED MONTHLY FORECAST MODEL + ITS PERSISTED ROLLING PROJECTION.
 *
 * One row per (institution, month, model). Written by
 * `php artisan forecast:train-monthly`, which runs on the LAST day of every month
 * to aggregate that month's data and update the model weights, and on the FIRST
 * day of the next month to persist the 3-month rolling forecast.
 *
 * WHY PERSIST AT ALL
 * ------------------
 * A projection that is recomputed on every page load is not a record of anything:
 * it silently changes as new meals are recorded, and there is no way to answer
 * "what did we forecast for this month, and were we right?" Persisting the
 * projection on a defined schedule makes the forecast an auditable artefact -
 * which is the same reason MealPriceEngine exposes its arithmetic rather than
 * just the answer.
 *
 * NOT `withoutTenantScope` BY DEFAULT
 * -----------------------------------
 * Unlike ForecastEmbedding (which the retrieval corpus reads across institutions
 * during a global training sweep), a model row belongs to exactly one workspace
 * and should be tenant-scoped in normal use. The training command lifts the scope
 * explicitly via TenantManager::runGlobally().
 */
class ForecastModel extends Model
{
    use BelongsToInstitution;

    protected $fillable = [
        'institution_id',
        'period_month',
        'model',
        'basis',
        'weights',
        'metrics',
        'forecast',
        'forecast_generated_at',
    ];

    protected $casts = [
        'period_month' => 'date',
        'weights' => 'array',
        'metrics' => 'array',
        'forecast' => 'array',
        'forecast_generated_at' => 'datetime',
    ];

    /**
     * The bases a model row can carry.
     *
     *   trained   - enough history; the weights were fitted from this institution
     *   benchmark - under the minimum history; country aggregates were used
     *   empty     - no usable data; every figure is a clean zero
     */
    public const BASES = ['trained', 'benchmark', 'empty'];

    /**
     * The most recent model row for an institution, optionally for a given month.
     *
     * Returns null when nothing has been trained yet, so a caller must decide how
     * to degrade rather than being handed a zero it might mistake for a real
     * forecast.
     */
    public static function latestFor(?int $institutionId, ?string $month = null, string $model = 'monthly-v1'): ?self
    {
        return static::withoutTenantScope()
            ->where('institution_id', $institutionId)
            ->where('model', $model)
            ->when($month !== null, fn ($q) => $q->where('period_month', $month))
            ->orderByDesc('period_month')
            ->first();
    }

    /**
     * The persisted rolling forecast, or an empty array when none was generated.
     *
     * Deliberately returns `[]` rather than null: the UI iterates it, and an empty
     * list renders as "not generated yet", which is the truth, instead of a
     * fabricated three-month table.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rollingForecast(): array
    {
        $forecast = $this->forecast;

        return is_array($forecast) ? $forecast : [];
    }
}
