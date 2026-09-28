<?php

namespace App\Http\Controllers\Meals;

use App\Http\Controllers\Controller;
use App\Models\ForecastBenchmark;
use App\Models\ForecastEmbedding;
use App\Models\Institution;
use App\Support\AuditLogger;
use App\Support\Forecaster;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * AI FORECASTING (RAG / VECTOR-BASED).
 *
 *   GET  /meals/forecasting            : the forecast, its evidence, and the basis
 *   POST /meals/forecasting/embed      : (re)build the historical vector corpus
 *   POST /meals/forecasting/benchmarks : record a country benchmark datapoint
 *
 * THE UI CONTRACT - what makes this trustworthy
 * ---------------------------------------------
 * The page always states which BASIS produced the number:
 *   - `history`   : a data-driven estimate from retrieved similar days, WITH the
 *                   actual days it used listed as evidence.
 *   - `benchmark` : a country-level fallback, because the institution has under the
 *                   minimum months of history. Labelled differently on purpose.
 *
 * A forecast is never presented as fact: `confidence`, `notes` and `evidence` all
 * travel with it so an operator can judge it - and disagree.
 */
class ForecastingController extends Controller
{
    public function index(Request $request)
    {
        $institution = Institution::current();

        $forecaster = new Forecaster($institution);

        // How much history exists, so the page can explain the basis up front.
        $historyStart = ForecastEmbedding::withoutTenantScope()
            ->where('institution_id', $institution?->id)
            ->where('model', 'v1-local')
            ->min('for_date');

        $monthsOfHistory = $historyStart
            ? Carbon::parse($historyStart)->diffInMonths(now())
            : 0;

        $historyDays = (int) $request->query('days', 7);
        $historyDays = max(min($historyDays, 30), 1);

        $range = $forecaster->forecastRange($historyDays);

        return Inertia::render('Meals/Forecasting/Index', [
            'forecast' => $range,
            // The single next-day forecast, with its full evidence trail.
            'tomorrow' => $forecaster->forecast(),
            'basis' => [
                'country' => $forecaster->countryCode(),
                'history_months' => round($monthsOfHistory, 1),
                'min_history_months' => (int) config('services.forecasting.min_history_months', 3),
                'embedded_days' => ForecastEmbedding::withoutTenantScope()
                    ->where('institution_id', $institution?->id)
                    ->where('model', 'v1-local')
                    ->count(),
                'has_history' => $monthsOfHistory >= (int) config('services.forecasting.min_history_months', 3),
            ],
            'days' => $historyDays,
            'benchmarks' => ForecastBenchmark::query()
                ->where('country_code', $forecaster->countryCode())
                ->orderByDesc('period_month')
                ->limit(20)
                ->get()
                ->map(fn (ForecastBenchmark $benchmark) => [
                    'id' => $benchmark->id,
                    'metric' => $benchmark->metric,
                    'metric_label' => ForecastBenchmark::METRICS[$benchmark->metric] ?? $benchmark->metric,
                    'period_month' => $benchmark->period_month?->format('F Y'),
                    'value' => (float) $benchmark->value,
                    'unit' => $benchmark->unit,
                    'source' => $benchmark->source,
                ]),
            'metricOptions' => collect(ForecastBenchmark::METRICS)
                ->map(fn (string $label, string $key) => ['value' => $key, 'label' => $label])
                ->values(),
        ]);
    }

    /**
     * (Re)build the historical embedding corpus.
     *
     * Idempotent: re-running overwrites each day's vector and outcomes, so the
     * corpus always reflects the current ledger. This is the step that makes the
     * forecast data-driven, so the UI exposes it explicitly.
     */
    public function embed(Request $request)
    {
        $institution = Institution::current();

        $count = (new Forecaster($institution))->buildEmbeddings();

        AuditLogger::log('updated', 'rebuilt the forecasting vector corpus', null, [
            'days' => $count,
        ], ['subject_label' => 'Forecasting', 'institution_id' => $institution?->id]);

        if ($count === 0) {
            return back()->with(
                'error',
                'No history could be embedded yet. Record some meals and expenses first.'
            );
        }

        return back()->with(
            'success',
            "Embedded {$count} day(s) of history. Forecasts will now use your own data."
        );
    }

    /** Record a country benchmark datapoint (the fallback corpus). */
    public function storeBenchmark(Request $request)
    {
        $data = $request->validate([
            'country_code' => ['required', 'string', 'size:2'],
            'metric' => ['required', Rule::in(array_keys(ForecastBenchmark::METRICS))],
            'period_month' => ['required', 'date'],
            'value' => ['required', 'numeric'],
            'unit' => ['nullable', 'string', 'max:24'],
            'source' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        // One datapoint per country+metric+month; a correction replaces it.
        $benchmark = ForecastBenchmark::updateOrCreate(
            [
                'country_code' => strtoupper($data['country_code']),
                'metric' => $data['metric'],
                'period_month' => Carbon::parse($data['period_month'])->startOfMonth()->toDateString(),
            ],
            [
                'value' => $data['value'],
                'unit' => $data['unit'] ?? null,
                'source' => $data['source'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]
        );

        AuditLogger::log('created', "recorded a {$benchmark->country_code} benchmark for {$benchmark->metric}", $benchmark, [
            'value' => (float) $benchmark->value,
        ], ['subject_label' => 'Forecast benchmark']);

        return back()->with('success', 'Benchmark saved. Institutions without enough history will fall back to it.');
    }
}
