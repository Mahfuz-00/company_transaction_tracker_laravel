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
    public function index(Request $request)
    {
        $institution = Institution::current();

        // Make sure the out-of-the-box sources exist before listing.
        SubsidySource::ensureDefaults($institution?->id);

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
