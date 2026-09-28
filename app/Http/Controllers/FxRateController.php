<?php

namespace App\Http\Controllers;

use App\Models\FxRate;
use App\Models\Institution;
use App\Support\AuditLogger;
use App\Support\TenantManager;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * MULTI-CURRENCY & FX RATE SNAPSHOTS.
 *
 *   GET  /settings/currencies       : the rate book + a cross-currency roll-up
 *   POST /settings/currencies       : record a rate snapshot
 *   DELETE /settings/currencies/{r} : remove a snapshot
 *
 * WHY THE SSA SEES THIS
 * ---------------------
 * Institutions bill in their own currency (a Dhaka dorm in BDT, a London hall in
 * GBP). The platform's revenue view must sum them, and you cannot add BDT to GBP
 * without a rate. This module is what makes the SSA's cross-institution reporting
 * meaningful rather than a meaningless sum of unlike numbers.
 *
 * RATES ARE SNAPSHOTS, NOT A LIVE FEED: each save records a rate AS OF a date, so
 * a figure reported last month does not silently change when today's rate moves.
 * `FxRate::convert()` reads the most recent snapshot on or before the date asked
 * for.
 */
class FxRateController extends Controller
{
    /** The SSA reporting currency - everything is converted into this for totals. */
    public const REPORTING_CURRENCY = 'USD';

    public function index(Request $request)
    {
        $rates = FxRate::query()
            ->with('created_by')
            ->orderByDesc('effective_on')
            ->orderBy('base_code')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (FxRate $rate) => [
                'id' => $rate->id,
                'base_code' => $rate->base_code,
                'quote_code' => $rate->quote_code,
                'rate' => (float) $rate->rate,
                'effective_on' => $rate->effective_on?->toDateString(),
                'source' => $rate->source,
                'created_by' => $rate->created_by,
                'created_at' => $rate->created_at?->format('j M Y H:i'),
            ]);

        return Inertia::render('Settings/CurrencyRates', [
            'rates' => $rates,
            'reportingCurrency' => self::REPORTING_CURRENCY,
            // The currencies actually in use, so the pickers only offer real ones.
            'activeCurrencies' => $this->activeCurrencies(),
            'rollup' => $this->rollup(),
        ]);
    }

    /** Record (or replace) a rate snapshot. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'base_code' => ['required', 'string', 'size:3'],
            'quote_code' => ['required', 'string', 'size:3', 'different:base_code'],
            'rate' => ['required', 'numeric', 'min:0.0000000001'],
            'effective_on' => ['required', 'date'],
            'source' => ['nullable', 'string', 'max:32'],
        ]);

        $rate = FxRate::record(
            strtoupper($data['base_code']),
            strtoupper($data['quote_code']),
            (float) $data['rate'],
            $data['effective_on'],
            $data['source'] ?? 'manual',
            $request->user()->id,
        );

        AuditLogger::log('created', "recorded an FX rate {$rate->base_code}->{$rate->quote_code}", $rate, [
            'rate' => (float) $rate->rate,
            'effective_on' => $rate->effective_on?->toDateString(),
        ], ['subject_label' => 'FX rates']);

        return back()->with(
            'success',
            "Rate saved: 1 {$rate->base_code} = {$rate->rate} {$rate->quote_code} on {$rate->effective_on->format('j M Y')}."
        );
    }

    public function destroy(Request $request, FxRate $fxRate)
    {
        $label = "{$fxRate->base_code}->{$fxRate->quote_code}";

        $fxRate->delete();

        return back()->with('success', "The rate snapshot {$label} was removed.");
    }

    /**
     * Every currency in use across the platform, from the institutions' own
     * `currency_code` values - so the pickers reflect reality, not a hardcoded list.
     */
    protected function activeCurrencies(): array
    {
        return app(TenantManager::class)->runGlobally(function () {
            return Institution::query()
                // A workspace with no explicit code uses the platform default.
                ->whereNotNull('currency_code')
                ->where('currency_code', '!=', '')
                ->distinct()
                ->orderBy('currency_code')
                ->pluck('currency_code')
                ->map(fn (string $code) => strtoupper($code))
                ->unique()
                ->values()
                ->all();
        });
    }

    /**
     * The cross-institution revenue roll-up, converted into the reporting currency.
     *
     * Each institution's subscription amount is converted using its OWN currency
     * and the most recent snapshot, and the result reports which institutions could
     * NOT be converted (no rate on file) rather than quietly dropping them from the
     * total.
     */
    protected function rollup(): array
    {
        return app(TenantManager::class)->runGlobally(function () {
            $institutions = Institution::query()
                ->whereNotNull('subscription_amount')
                ->get(['id', 'name', 'currency_code', 'subscription_amount', 'subscription_status']);

            $rows = [];
            $total = 0.0;
            $unconverted = [];

            foreach ($institutions as $institution) {
                $currency = strtoupper((string) ($institution->currency_code ?: self::REPORTING_CURRENCY));
                $amount = (float) $institution->subscription_amount;

                $converted = FxRate::convert($amount, $currency, self::REPORTING_CURRENCY);

                if ($converted === null) {
                    // No rate on file: surface it instead of silently omitting the
                    // institution from platform revenue.
                    $unconverted[] = [
                        'institution' => $institution->name,
                        'currency' => $currency,
                        'amount' => $amount,
                    ];

                    $rows[] = [
                        'institution' => $institution->name,
                        'currency' => $currency,
                        'amount' => $amount,
                        'converted' => null,
                        'status' => $institution->subscription_status,
                    ];

                    continue;
                }

                $total += $converted;

                $rows[] = [
                    'institution' => $institution->name,
                    'currency' => $currency,
                    'amount' => $amount,
                    'converted' => $converted,
                    'status' => $institution->subscription_status,
                ];
            }

            return [
                'reporting_currency' => self::REPORTING_CURRENCY,
                'total' => round($total, 2),
                'rows' => $rows,
                'unconverted' => $unconverted,
                'converted_count' => count($rows) - count($unconverted),
                'unconverted_count' => count($unconverted),
            ];
        });
    }
}
