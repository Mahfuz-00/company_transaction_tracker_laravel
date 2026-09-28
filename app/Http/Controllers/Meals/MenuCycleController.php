<?php

namespace App\Http\Controllers\Meals;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\MenuCycle;
use App\Models\MenuCycleDay;
use App\Models\MenuIngredient;
use App\Models\Vendor;
use App\Support\AuditLogger;
use App\Support\ProcurementForecaster;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * MENU CYCLE & PROCUREMENT FORECASTS.
 *
 *   GET    /meals/menu-cycle                 : cycles + the selected cycle's forecast
 *   POST   /meals/menu-cycle                 : create a cycle
 *   PUT    /meals/menu-cycle/{cycle}         : rename / retune a cycle
 *   DELETE /meals/menu-cycle/{cycle}         : delete
 *   PUT    /meals/menu-cycle/{cycle}/days/{n}: set one day's dishes
 *   POST   /meals/menu-cycle/{cycle}/ingredients : add an ingredient line
 *   DELETE /meals/menu-cycle/ingredients/{i} : remove an ingredient line
 *
 * The forecast is computed on read (ProcurementForecaster), never stored, so it
 * always reflects the current menu, roster and prices.
 */
class MenuCycleController extends Controller
{
    public function index(Request $request)
    {
        $cycles = MenuCycle::query()
            ->withCount('days')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (MenuCycle $cycle) => [
                'id' => $cycle->id,
                'name' => $cycle->name,
                'description' => $cycle->description,
                'cycle_length' => $cycle->cycle_length,
                'starts_on' => $cycle->starts_on?->toDateString(),
                'is_active' => $cycle->is_active,
                'days_count' => $cycle->days_count,
            ]);

        // Which cycle is being viewed (defaults to the active one).
        $selectedId = (int) $request->query('cycle', 0);
        $selected = $selectedId
            ? MenuCycle::with(['days', 'ingredients.vendor:id,name'])->find($selectedId)
            : MenuCycle::query()->with(['days', 'ingredients.vendor:id,name'])->where('is_active', true)->first();

        // The turnout rate is a query input so an admin can model "what if only 70%
        // eat today?" without changing any stored data.
        $turnout = (float) $request->query('turnout', ProcurementForecaster::DEFAULT_TURNOUT);
        $turnout = max(min($turnout, 1.0), 0.0);

        $forecast = $selected
            ? (new ProcurementForecaster)->forecastCycle($selected, $turnout)
            : null;

        return Inertia::render('Meals/MenuCycle/Index', [
            'cycles' => $cycles,
            'selected' => $selected ? [
                'id' => $selected->id,
                'name' => $selected->name,
                'description' => $selected->description,
                'cycle_length' => $selected->cycle_length,
                'starts_on' => $selected->starts_on?->toDateString(),
                'is_active' => $selected->is_active,
                'days' => $selected->days->map(fn (MenuCycleDay $day) => [
                    'id' => $day->id,
                    'day_number' => $day->day_number,
                    'label' => $day->label,
                    'dishes' => $day->dishList(),
                ]),
                'ingredients' => $selected->ingredients->map(fn (MenuIngredient $ingredient) => [
                    'id' => $ingredient->id,
                    'dish' => $ingredient->dish,
                    'name' => $ingredient->name,
                    'unit' => $ingredient->unit,
                    'qty_per_serving' => (float) $ingredient->qty_per_serving,
                    'unit_cost' => (float) $ingredient->unit_cost,
                    'vendor_id' => $ingredient->vendor_id,
                    'vendor' => $ingredient->vendor?->name,
                ]),
            ] : null,
            'forecast' => $forecast,
            'turnout' => $turnout,
            'vendors' => Vendor::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'cycle_length' => ['required', 'integer', 'min:1', 'max:31'],
            'starts_on' => ['nullable', 'date'],
            'is_active' => ['boolean'],
        ]);

        $cycle = MenuCycle::create([
            'institution_id' => Institution::current()?->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'cycle_length' => $data['cycle_length'],
            'starts_on' => $data['starts_on'] ?? now()->startOfWeek()->toDateString(),
            'is_active' => $request->boolean('is_active', true),
        ]);

        // Pre-create the day rows so the planner always has the right number of
        // editable days, however the cycle length changes.
        for ($day = 1; $day <= $cycle->cycle_length; $day++) {
            $cycle->days()->create([
                'day_number' => $day,
                'label' => 'Day '.$day,
                'dishes' => [],
            ]);
        }

        AuditLogger::log('created', "created the menu cycle \"{$cycle->name}\"", $cycle, [
            'cycle_length' => $cycle->cycle_length,
        ], ['subject_label' => $cycle->name, 'institution_id' => $cycle->institution_id]);

        return redirect()
            ->route('meals.menu-cycle.index', ['cycle' => $cycle->id])
            ->with('success', "Menu cycle \"{$cycle->name}\" created.");
    }

    public function update(Request $request, MenuCycle $menuCycle)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'cycle_length' => ['required', 'integer', 'min:1', 'max:31'],
            'starts_on' => ['nullable', 'date'],
            'is_active' => ['boolean'],
        ]);

        $menuCycle->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'cycle_length' => $data['cycle_length'],
            'starts_on' => $data['starts_on'] ?? $menuCycle->starts_on,
            'is_active' => $request->boolean('is_active'),
        ]);

        // Keep the day rows aligned with the (possibly changed) length, without
        // ever destroying days that already have a menu planned.
        for ($day = 1; $day <= $menuCycle->cycle_length; $day++) {
            $menuCycle->days()->firstOrCreate(
                ['day_number' => $day],
                ['label' => 'Day '.$day, 'dishes' => []]
            );
        }

        // Days beyond the new length are removed only if they are empty, so a
        // shortened cycle never silently discards planned dishes.
        $menuCycle->days()
            ->where('day_number', '>', $menuCycle->cycle_length)
            ->get()
            ->each(function (MenuCycleDay $day) {
                if ($day->dishList() === []) {
                    $day->delete();
                }
            });

        return back()->with('success', 'Menu cycle updated.');
    }

    public function destroy(MenuCycle $menuCycle)
    {
        $name = $menuCycle->name;
        $menuCycle->delete();

        return redirect()
            ->route('meals.menu-cycle.index')
            ->with('success', "Menu cycle \"{$name}\" deleted.");
    }

    /** Set the dishes served on one day of a cycle. */
    public function updateDay(Request $request, MenuCycle $menuCycle, int $dayNumber)
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:60'],
            // Each dish: meal slot, dish name, servings per person.
            'dishes' => ['array'],
            'dishes.*.meal' => ['required', Rule::in(['breakfast', 'lunch', 'dinner', 'snack'])],
            'dishes.*.dish' => ['required', 'string', 'max:120'],
            'dishes.*.servings' => ['nullable', 'numeric', 'min:0', 'max:10'],
        ]);

        $dishes = collect($data['dishes'] ?? [])
            ->map(fn (array $dish) => [
                'meal' => $dish['meal'],
                'dish' => trim($dish['dish']),
                'servings' => (float) ($dish['servings'] ?? 1),
            ])
            ->values()
            ->all();

        $day = $menuCycle->days()->firstOrCreate(
            ['day_number' => $dayNumber],
            ['label' => 'Day '.$dayNumber, 'dishes' => []]
        );

        $day->update([
            'label' => $data['label'] ?? $day->label,
            'dishes' => $dishes,
        ]);

        return back()->with('success', "Day {$dayNumber} menu saved.");
    }

    /** Add an ingredient line that the forecast will use. */
    public function storeIngredient(Request $request, MenuCycle $menuCycle)
    {
        $data = $request->validate([
            'dish' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:120'],
            'unit' => ['required', 'string', 'max:24'],
            'qty_per_serving' => ['required', 'numeric', 'min:0'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'vendor_id' => ['nullable', 'exists:vendors,id'],
        ]);

        $menuCycle->ingredients()->create([
            'institution_id' => $menuCycle->institution_id,
            'dish' => $data['dish'],
            'name' => $data['name'],
            'unit' => $data['unit'],
            'qty_per_serving' => $data['qty_per_serving'],
            'unit_cost' => $data['unit_cost'],
            'vendor_id' => $data['vendor_id'] ?? null,
        ]);

        return back()->with('success', "\"{$data['name']}\" added to the forecast.");
    }

    public function destroyIngredient(MenuIngredient $ingredient)
    {
        $name = $ingredient->name;
        $ingredient->delete();

        return back()->with('success', "\"{$name}\" removed from the forecast.");
    }
}
