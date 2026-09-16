<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class InstitutionController extends Controller
{
    /**
     * Which term keys an admin can override, with friendly labels. Kept in one
     * place so the settings UI and validation never drift apart.
     */
    public const EDITABLE_TERMS = [
        'member' => 'One member (e.g. Student)',
        'members' => 'Many members (e.g. Students)',
        'department' => 'One group (e.g. Department)',
        'departments' => 'Many groups (e.g. Departments)',
        'deposit' => 'One deposit',
        'deposits' => 'Many deposits',
        'meal' => 'One meal',
        'meals' => 'Many meals',
        'meal_manager' => 'Manager title',
        'institution' => 'Institution noun (e.g. Hall)',
    ];

    public function edit()
    {
        $institution = Institution::current();

        return Inertia::render('Settings/InstitutionSettings', [
            'institution' => $institution ? [
                'id' => $institution->id,
                'name' => $institution->name,
                'slug' => $institution->slug,
                'type' => $institution->type,
                'currency_code' => $institution->currency_code,
                'timezone' => $institution->timezone,
                'address' => $institution->address,
                'contact_email' => $institution->contact_email,
                'contact_phone' => $institution->contact_phone,
                'terminology' => $institution->terminology ?? [],
                'terms' => $institution->terminologyMap(),
            ] : null,
            'types' => collect(Institution::TYPES)
                ->map(fn ($preset, $key) => [
                    'value' => $key,
                    'label' => $preset['label'],
                    'description' => $preset['description'],
                    'terms' => $preset['terms'],
                ])
                ->values(),
            'termKeys' => collect(self::EDITABLE_TERMS)
                ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])
                ->values(),
        ]);
    }

    public function update(Request $request)
    {
        $institution = Institution::current();

        // First run: there may be no institution yet, so create one.
        if (! $institution) {
            $institution = new Institution(['name' => 'My Institution']);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(array_keys(Institution::TYPES))],
            'currency_code' => ['nullable', 'string', 'max:10'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'terminology' => ['nullable', 'array'],
            // Reject unknown term keys rather than storing dead config.
            'terminology.*' => ['nullable', 'string', 'max:60'],
        ]);

        // Drop blank overrides so the type preset shows through again.
        $overrides = collect($data['terminology'] ?? [])
            ->only(array_keys(self::EDITABLE_TERMS))
            ->filter(fn ($value) => filled($value))
            ->all();

        $data['terminology'] = $overrides ?: null;

        $institution->fill($data);
        $institution->is_active = true;
        $institution->save();

        return redirect()
            ->route('settings.institution.edit')
            ->with('success', 'Institution settings saved. Terminology updated across the app.');
    }
}
