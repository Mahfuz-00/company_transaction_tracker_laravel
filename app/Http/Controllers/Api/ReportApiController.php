<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Support\FinanceCalculator;
use App\Support\Money;
use Illuminate\Http\Request;

/**
 * Reporting + analytics API, including the predictive forecast.
 *
 * The per-meal rate is always total expense / total meals for the month.
 */
class ReportApiController extends Controller
{
    /** The full meal report for a month: summary + per-member breakdown. */
    public function meal(Request $request)
    {
        $month = FinanceCalculator::resolveMonth($request->query('month'));
        $finance = new FinanceCalculator();

        $snapshot = $finance->monthSnapshot($month);
        $members = $finance->memberBreakdown($month);

        return response()->json([
            'data' => [
                'month' => $month,
                'label' => $snapshot['label'],
                'summary' => $snapshot,
                'members' => $members->sortByDesc('meals')->values(),
                'currency' => Institution::current()?->currencySettings(),
            ],
        ]);
    }

    /** Analytics summary with subsidy tracking. */
    public function analytics(Request $request)
    {
        $month = FinanceCalculator::resolveMonth($request->query('month'));
        $finance = new FinanceCalculator();

        $snapshot = $finance->monthSnapshot($month);

        return response()->json([
            'data' => [
                'month' => $month,
                'snapshot' => $snapshot,
                'subsidy_tracking' => [
                    'total' => $snapshot['subsidies'],
                    'coverage_pct' => $snapshot['subsidy_coverage_pct'],
                ],
                'forecast' => $finance->forecast(),
            ],
        ]);
    }

    /** Just the forecast - handy for a dedicated mobile screen. */
    public function forecast(Request $request)
    {
        $lookback = (int) $request->query('months', FinanceCalculator::FORECAST_LOOKBACK_MONTHS);
        $lookback = max(2, min(12, $lookback));

        $finance = new FinanceCalculator();

        return response()->json(['data' => $finance->forecast($lookback)]);
    }

    /**
     * The per-meal rate for a month, broken into the parts that make it up:
     * total expense, total meals, and the resulting rate.
     */
    public function perMealRate(Request $request)
    {
        $month = FinanceCalculator::resolveMonth($request->query('month'));
        $finance = new FinanceCalculator();

        $snapshot = $finance->monthSnapshot($month);

        return response()->json([
            'data' => [
                'month' => $month,
                'formula' => 'per_meal_rate = total_expense / total_meals',
                'total_expense' => $snapshot['expenses'],
                'total_meals' => $snapshot['meals'],
                'per_meal_rate' => $snapshot['per_meal_rate'],
                'daily_meals' => $snapshot['daily_meals'],
                'daily_cost' => $snapshot['daily_cost'],
                'meals_per_member' => $snapshot['meals_per_member'],
                'cost_per_member' => $snapshot['cost_per_member'],
                'subsidy_coverage_pct' => $snapshot['subsidy_coverage_pct'],
                'member_funded_pct' => $snapshot['member_funded_pct'],
                'formatted' => [
                    'total_expense' => Money::format($snapshot['expenses']),
                    'per_meal_rate' => Money::format($snapshot['per_meal_rate'], null, false),
                ],
            ],
        ]);
    }
}
