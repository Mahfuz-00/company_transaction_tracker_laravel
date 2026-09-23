<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Institution (workspace) settings - identity, type, terminology and branding.
 *
 * WHAT THIS MANAGES
 * -----------------
 * The single row describing the workspace the request is acting inside: its
 * display name and subtitle, business `type` (university, company, mess, ...),
 * the editable TERMINOLOGY overrides ("member" vs "student", "department" vs
 * "group"), and the logo/banner images. Every module renders its labels and
 * branding from this data, which is what lets one codebase present itself as a
 * university, a hostel mess or a corporate canteen.
 *
 * TENANCY NOTE
 * ------------
 * The target is always `Institution::current()` - the institution resolved for
 * the active request - NOT a route parameter the caller could point elsewhere.
 * An admin can therefore only ever read and write their OWN workspace, and there
 * is no id in the URL that could be tampered with to reach another tenant.
 */
class InstitutionController extends Controller
{
    /**
     * Which term keys an admin can override, with friendly labels. Kept in one
     * place so the settings UI and validation never drift apart.
     */
    public const EDITABLE_TERMS = [
        'member' => 'One member (e.g. Student)',
        'members' => 'Many members (e.g. Students)',
        'participant' => 'One participant (e.g. Employee)',
        'participants' => 'Many participants (e.g. Employees)',
        'department' => 'One group (e.g. Department)',
        'departments' => 'Many groups (e.g. Departments)',
        'deposit' => 'One deposit',
        'deposits' => 'Many deposits',
        'meal' => 'One meal',
        'meals' => 'Many meals',
        'meal_manager' => 'Manager title',
        'institution' => 'Institution noun (e.g. Hall, Company, Mess)',
    ];

    /**
     * Render the institution settings form.
     *
     * The Eloquent model is reshaped into a plain array before being handed to
     * the Inertia page, so only the fields the UI actually needs cross the
     * server -> client JSON boundary (and computed helpers such as
     * `terminologyMap()` / `logoUrl()` are evaluated server-side).
     */
    public function edit()
    {
        $institution = Institution::current();

        return Inertia::render('Settings/InstitutionSettings', [
            'institution' => $institution ? [
                'id' => $institution->id,
                'name' => $institution->name,
                'subtitle' => $institution->subtitle,
                'slug' => $institution->slug,
                'type' => $institution->type,
                'currency_code' => $institution->currency_code,
                'timezone' => $institution->timezone,
                'address' => $institution->address,
                'contact_email' => $institution->contact_email,
                'contact_phone' => $institution->contact_phone,
                'terminology' => $institution->terminology ?? [],
                'terms' => $institution->terminologyMap(),
                // Branding only. Theme customisation deliberately lives in its
                // OWN sub-module (Settings > Theme Customizer), reachable by all
                // users, so it is no longer configured here.
                'logo_url' => $institution->logoUrl(),
                'banner_url' => $institution->bannerUrl(),
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

    /**
     * Persist institution settings (creating the row on first run).
     *
     * Flow: validate once up front, massage the validated array (terminology
     * overrides, uploaded images), then mass-assign it with `fill()` and save.
     * `$request->validate()` throws a ValidationException on failure which Laravel
     * converts into a redirect-with-errors for a normal Inertia form post, so no
     * manual error handling is needed here.
     */
    public function update(Request $request)
    {
        $institution = Institution::current();

        // First run: there may be no institution yet, so create one.
        if (! $institution) {
            $institution = new Institution(['name' => 'My Institution']);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:160'],
            'type' => ['required', Rule::in(array_keys(Institution::TYPES))],
            'currency_code' => ['nullable', 'string', 'max:10'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'terminology' => ['nullable', 'array'],
            // Reject unknown term keys rather than storing dead config.
            'terminology.*' => ['nullable', 'string', 'max:60'],
            // NOTE: Theme is no longer accepted here - it moved to the dedicated
            // Theme Customizer (per-user). Institution Settings only manages
            // identity, type, terminology and branding.
            // Branding images.
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
            'banner' => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:4096'],
            'remove_logo' => ['boolean'],
            'remove_banner' => ['boolean'],
        ]);

        // Drop blank overrides so the type preset shows through again.
        $overrides = collect($data['terminology'] ?? [])
            ->only(array_keys(self::EDITABLE_TERMS))
            ->filter(fn ($value) => filled($value))
            ->all();

        $data['terminology'] = $overrides ?: null;

        // --- Branding uploads -------------------------------------------
        if ($request->boolean('remove_logo') && $institution->logo_path) {
            Storage::disk('public')->delete($institution->logo_path);
            $data['logo_path'] = null;
        }

        if ($request->hasFile('logo')) {
            if ($institution->logo_path) {
                Storage::disk('public')->delete($institution->logo_path);
            }
            $data['logo_path'] = $request->file('logo')->store('institution/logos', 'public');
        }

        if ($request->boolean('remove_banner') && $institution->banner_path) {
            Storage::disk('public')->delete($institution->banner_path);
            $data['banner_path'] = null;
        }

        if ($request->hasFile('banner')) {
            if ($institution->banner_path) {
                Storage::disk('public')->delete($institution->banner_path);
            }
            $data['banner_path'] = $request->file('banner')->store('institution/banners', 'public');
        }

        // The file inputs and the remove_* flags are request-only helpers, not
        // columns on the institution: strip them so fill() cannot trip over a key
        // it cannot map. `logo_path` / `banner_path` (the stored paths) stay.
        unset($data['logo'], $data['banner'], $data['remove_logo'], $data['remove_banner']);

        $institution->fill($data);
        $institution->is_active = true;
        $institution->save();

        AuditLogger::log('updated', 'updated institution settings', $institution, [
            'type' => $institution->type,
            'terminology_count' => count($overrides),
        ], ['subject_label' => $institution->name]);

        return redirect()
            ->route('settings.institution.edit')
            ->with('success', 'Institution settings saved. Terminology updated across the app.');
    }
}
