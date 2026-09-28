<?php

namespace App\Support;

use App\Models\MenuCycle;
use App\Models\MenuIngredient;
use App\Models\Student;

/**
 * PROCUREMENT FORECASTS FROM A MENU CYCLE.
 *
 * A menu cycle answers "what are we cooking this week". Procurement answers "what
 * must we buy, and what will it cost". The two are only useful TOGETHER: a menu
 * without quantities is a poster, not a plan.
 *
 * THE CALCULATION
 *   expected eaters      = active members (scaled by a configurable turnout rate)
 *   servings for a dish  = expected eaters × servings per person for that dish
 *   quantity of an item  = servings × qty_per_serving
 *   cost of an item      = quantity × unit_cost
 *
 * Everything is derived - nothing is stored - so the forecast always reflects the
 * current menu, roster and prices rather than a stale snapshot.
 *
 * The turnout rate matters: a hostel with 300 members rarely serves 300 dinners.
 * Defaulting to 100% would systematically over-order, so it is an explicit input.
 */
class ProcurementForecaster
{
    /** Default share of the roster expected to eat on a given day. */
    public const DEFAULT_TURNOUT = 1.0;

    /**
     * The forecast for one day of a cycle.
     *
     * @return array{
     *   day_number:int, label:string, eaters:int, dishes:array, items:array,
     *   total_cost:float, warnings:array
     * }
     */
    public function forecastDay(MenuCycle $cycle, int $dayNumber, ?float $turnout = null): array
    {
        $turnout ??= self::DEFAULT_TURNOUT;

        $day = $cycle->days()->where('day_number', $dayNumber)->first();

        if (! $day) {
            return [
                'day_number' => $dayNumber,
                'label' => "Day {$dayNumber}",
                'eaters' => 0,
                'dishes' => [],
                'items' => [],
                'total_cost' => 0.0,
                'warnings' => ["Day {$dayNumber} has no menu planned."],
            ];
        }

        // Active members in the institution (the tenant scope already applied).
        $roster = Student::active()->count();

        // Never forecast for zero people: that would silently produce an empty
        // shopping list that looks like a valid plan.
        $eaters = max((int) round($roster * $turnout), 0);

        $warnings = [];

        if ($roster === 0) {
            $warnings[] = 'There are no active members on the roster, so no quantities could be forecast.';
        }

        $dishes = $day->dishList();

        // Aggregate ingredient demand ACROSS dishes: if the same item is used in
        // lunch and dinner it must be bought for both, in one line.
        $demand = [];

        foreach ($dishes as $dish) {
            $dishName = (string) ($dish['dish'] ?? '');
            // Servings of this dish per person (default 1).
            $servingsPerPerson = (float) ($dish['servings'] ?? 1);
            $totalServings = $eaters * $servingsPerPerson;

            if ($dishName === '') {
                continue;
            }

            $ingredients = MenuIngredient::query()
                ->where('menu_cycle_id', $cycle->id)
                ->where('dish', $dishName)
                ->get();

            if ($ingredients->isEmpty()) {
                $warnings[] = "Dish \"{$dishName}\" has no ingredients defined, so it contributes nothing to the forecast.";

                continue;
            }

            foreach ($ingredients as $ingredient) {
                $key = strtolower($ingredient->name).'|'.$ingredient->unit;

                if (! isset($demand[$key])) {
                    $demand[$key] = [
                        'name' => $ingredient->name,
                        'unit' => $ingredient->unit,
                        'quantity' => 0.0,
                        'unit_cost' => (float) $ingredient->unit_cost,
                        'vendor_id' => $ingredient->vendor_id,
                        'used_in' => [],
                    ];
                }

                $demand[$key]['quantity'] += $totalServings * (float) $ingredient->qty_per_serving;

                // Keep the highest known unit cost so the estimate is not optimistic.
                $demand[$key]['unit_cost'] = max($demand[$key]['unit_cost'], (float) $ingredient->unit_cost);
                $demand[$key]['used_in'][] = $dishName;
            }
        }

        $items = [];
        $totalCost = 0.0;

        foreach ($demand as $entry) {
            $quantity = round($entry['quantity'], 3);
            $cost = round($quantity * $entry['unit_cost'], 2);
            $totalCost += $cost;

            if ($entry['unit_cost'] <= 0) {
                $warnings[] = "No unit cost is set for \"{$entry['name']}\", so its cost is not in the total.";
            }

            $items[] = [
                'name' => $entry['name'],
                'unit' => $entry['unit'],
                'quantity' => $quantity,
                'unit_cost' => $entry['unit_cost'],
                'cost' => $cost,
                'vendor_id' => $entry['vendor_id'],
                'used_in' => array_values(array_unique($entry['used_in'])),
            ];
        }

        // Most expensive first: a buyer starts with the big-ticket lines.
        usort($items, fn ($a, $b) => $b['cost'] <=> $a['cost']);

        return [
            'day_number' => $dayNumber,
            'label' => $day->label ?: "Day {$dayNumber}",
            'eaters' => $eaters,
            'dishes' => $dishes,
            'items' => $items,
            'total_cost' => round($totalCost, 2),
            'warnings' => $warnings,
        ];
    }

    /**
     * The forecast for the WHOLE cycle, plus a per-item roll-up.
     *
     * @return array{days:array, items:array, total_cost:float, eaters:int, warnings:array}
     */
    public function forecastCycle(MenuCycle $cycle, ?float $turnout = null): array
    {
        $days = [];
        $consolidated = [];
        $warnings = [];
        $totalCost = 0.0;
        $eaters = 0;

        for ($day = 1; $day <= $cycle->cycle_length; $day++) {
            $forecast = $this->forecastDay($cycle, $day, $turnout);

            $days[] = $forecast;
            $totalCost += $forecast['total_cost'];
            $eaters = max($eaters, $forecast['eaters']);
            $warnings = array_merge($warnings, $forecast['warnings']);

            foreach ($forecast['items'] as $item) {
                $key = strtolower($item['name']).'|'.$item['unit'];

                if (! isset($consolidated[$key])) {
                    $consolidated[$key] = [
                        'name' => $item['name'],
                        'unit' => $item['unit'],
                        'quantity' => 0.0,
                        'unit_cost' => $item['unit_cost'],
                        'vendor_id' => $item['vendor_id'],
                        'cost' => 0.0,
                    ];
                }

                $consolidated[$key]['quantity'] += $item['quantity'];
                $consolidated[$key]['cost'] += $item['cost'];
                $consolidated[$key]['unit_cost'] = max($consolidated[$key]['unit_cost'], $item['unit_cost']);
            }
        }

        // A cycle-wide shopping list: the single thing a buyer actually needs.
        $items = array_values(array_map(function (array $entry) {
            $entry['quantity'] = round($entry['quantity'], 3);
            $entry['cost'] = round($entry['cost'], 2);

            return $entry;
        }, $consolidated));

        usort($items, fn ($a, $b) => $b['cost'] <=> $a['cost']);

        return [
            'days' => $days,
            'items' => $items,
            'total_cost' => round($totalCost, 2),
            'eaters' => $eaters,
            // De-duplicated so the same missing-price warning appears once.
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * A suggested PURCHASE ORDER draft from a day's forecast, grouped by vendor.
     *
     * This is the bridge between planning and the procure-to-pay flow: the buyer
     * reviews the draft rather than retyping the quantities.
     *
     * @return array<int, array{vendor_id:?int, items:array, subtotal:float}>
     */
    public function draftPurchaseOrders(MenuCycle $cycle, int $dayNumber, ?float $turnout = null): array
    {
        $forecast = $this->forecastDay($cycle, $dayNumber, $turnout);

        $byVendor = [];

        foreach ($forecast['items'] as $item) {
            $vendorId = $item['vendor_id'] ?? 0;

            $byVendor[$vendorId] ??= [
                'vendor_id' => $vendorId ?: null,
                'items' => [],
                'subtotal' => 0.0,
            ];

            $byVendor[$vendorId]['items'][] = [
                'description' => $item['name'],
                'unit' => $item['unit'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_cost'],
                'line_total' => $item['cost'],
            ];

            $byVendor[$vendorId]['subtotal'] += $item['cost'];
        }

        return array_values(array_map(function (array $group) {
            $group['subtotal'] = round($group['subtotal'], 2);

            return $group;
        }, $byVendor));
    }
}
