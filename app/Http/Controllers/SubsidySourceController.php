<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use App\Models\SubsidySource;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Admin management of subsidy funding sources.
 *
 * Each institution funds its meals differently (a university has an authority,
 * a company has management, a college has grants), so the list of sources is
 * editable rather than hard-coded. Each source can carry a default percentage:
 * the share of the pool it is expected to carry.
 */
class SubsidySourceController extends Controller
{
    /**
     * List this institution's funding sources.
     *
     * `ensureDefaults()` runs first so the out-of-the-box sources always exist -
     * it is an idempotent `firstOrCreate`, safe to call on every page load. The
     * list then shows the institution's OWN sources plus the shared,
     * platform-wide defaults (`institution_id` NULL). `withCount` gives each row
     * its usage count in a single extra query instead of one per row.
     */
    public function index(Request $request)
    {
        $institution = Institution::current();

        // Make sure the out-of-the-box sources exist before listing.
        SubsidySource::ensureDefaults($institution?->id);

        // "Mine OR the shared defaults" - the model's overridden global scope
        // already widens SubsidySource this way; repeating the clause here keeps
        // the rule visible at the point it is relied upon (and covers the global
        // /SSA context where the tenant scope adds nothing).
        $sources = SubsidySource::query()
            ->where(fn ($q) => $q->whereNull('institution_id')
                ->orWhere('institution_id', $institution?->id))
            ->withCount(['subsidies as subsidy_count'])
            ->orderBy('name')
            ->get()
            ->map(fn (SubsidySource $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'key' => $s->key,
                'percentage' => $s->percentage !== null ? (float) $s->percentage : null,
                'description' => $s->description,
                'is_active' => (bool) $s->is_active,
                'subsidy_count' => $s->subsidy_count,
            ]);

        // The default split, for the summary bar.
        $totalPct = $sources->where('is_active', true)->sum(fn ($s) => $s['percentage'] ?? 0);

        return Inertia::render('Settings/SubsidySources', [
            'sources' => $sources,
            'totalPercentage' => round((float) $totalPct, 2),
        ]);
    }

    /**
     * Add a funding source for the current institution.
     *
     * `institution_id` is pinned to `Institution::current()` SERVER-SIDE, never
     * taken from the request, so a source is always created inside the caller's
     * own tenant. The explicit duplicate check enforces uniqueness of the derived
     * `key` within that tenant - two sources sharing a key would collide in any
     * report that groups by key.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'key' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'],
            'percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $institution = Institution::current();

        // Derive a stable key from the name when none was supplied.
        $data['key'] = $data['key'] ?? Str::slug($data['name'], '_');
        $data['institution_id'] = $institution?->id;
        $data['is_active'] = true;

        // Two sources with the same key would collide in reports.
        $exists = SubsidySource::where('key', $data['key'])
            ->where(fn ($q) => $q->whereNull('institution_id')->orWhere('institution_id', $institution?->id))
            ->exists();

        if ($exists) {
            return back()->withErrors(['name' => 'A funding source with this key already exists.']);
        }

        SubsidySource::create($data);

        return back()->with('success', "Funding source \"{$data['name']}\" added.");
    }

    /**
     * Update one funding source.
     *
     * `$subsidySource` is resolved by ROUTE-MODEL BINDING, which runs the model's
     * tenant global scope - so a source belonging to another institution 404s
     * before this body is ever reached and cannot be edited across tenants. `key`
     * is deliberately NOT accepted here: recorded subsidies reference it, so
     * changing it would silently detach historical rows.
     */
    public function update(Request $request, SubsidySource $subsidySource)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ]);

        $subsidySource->update($data);

        return back()->with('success', "Funding source \"{$subsidySource->name}\" updated.");
    }

    /**
     * Remove a funding source - unless recorded subsidies reference it.
     *
     * A source already used in financial history must not be deleted: the
     * `subsidies` relation matches on `key`, so deleting the row would orphan
     * those records. The user is told to DEACTIVATE it instead, which keeps the
     * history intact while hiding the source from new-entry pickers.
     */
    public function destroy(SubsidySource $subsidySource)
    {
        // A source already used by recorded subsidies is financial history.
        if ($subsidySource->subsidies()->exists()) {
            return back()->with(
                'error',
                "Cannot delete \"{$subsidySource->name}\" - subsidies reference it. Deactivate it instead."
            );
        }

        $name = $subsidySource->name;
        $subsidySource->delete();

        return back()->with('success', "Funding source \"{$name}\" removed.");
    }
}
