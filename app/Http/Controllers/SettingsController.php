<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\Institution;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Global Currency Manager.
 *
 * A single, system-wide currency configuration owned by the Software Super
 * Admin. Stored on the institution so every module, report and dashboard
 * formats money identically.
 */
class SettingsController extends Controller
{
    /**
     * Show the Currency Manager.
     *
     * Currency is a WORKSPACE setting: the page always reads the current
     * institution's configuration (`Institution::current()`), never a per-user
     * preference. `canManage` is computed and passed to the view so the form
     * renders read-only for users who may view but not edit; the POST is
     * independently re-checked in store() - the client flag is a UI hint, never
     * the security boundary.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $institution = Institution::current();

        // Currency is GLOBAL and institutional. There is deliberately NO
        // per-user currency setting: removing 'userSettings' means a member can
        // never override the institution's chosen format. Every screen reads the
        // one shared `currency` prop (see HandleInertiaRequests).
        /*
         * WHO MAY MANAGE CURRENCY.
         *
         * The currency format is a WORKSPACE setting, so it belongs to the
         * institution's own administrator - not only the Software Super Admin.
         * Both may edit it for the workspace they are currently in:
         *   - Institution Admin : their own institution, always.
         *   - Software Super Admin : the workspace they have switched into.
         * A regular member/manager can still VIEW the page (so they understand
         * the format) but canManage is false and the POST is refused.
         */
        $canManage = $user->isSuperAdmin()
            || ($user->isInstitutionAdmin()
                && $institution
                && $user->belongsToInstitution($institution->id));

        return Inertia::render('Settings/CurrencyManager', [
            'currencies' => Currency::orderBy('name')->get(),
            'currencySettings' => $institution?->currencySettings()
                ?? Institution::DEFAULT_CURRENCY_SETTINGS,
            'canManage' => $canManage,
        ]);
    }

    /**
     * Persist the institution's currency format.
     *
     * The write is authorised twice: the form only posts for a manager/SSA, and
     * that is re-checked here because an HTTP POST can be forged regardless of
     * what the previous page rendered. The payload is then validated,
     * sanity-checked (the two separators must differ or parsing breaks), folded
     * into the institution's `currency_settings` JSON column, and audited.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $institution = Institution::current();

        /*
         * AUTHORISATION: the Software Super Admin OR the Institution Admin of
         * the active workspace. Everyone else (members, meal managers) is
         * refused so an individual user can never alter currency formatting.
         */
        $allowed = $user->isSuperAdmin()
            || ($user->isInstitutionAdmin()
                && $institution
                && $user->belongsToInstitution($institution->id));

        if (! $allowed) {
            return back()->with('error', 'You do not have permission to change this institution\'s currency format.');
        }

        $validated = $request->validate([
            'currency_code' => ['nullable', 'string', 'max:10'],
            'symbol' => ['nullable', 'string', 'max:12'],
            'symbol_position' => ['nullable', Rule::in(['before', 'before_space', 'after', 'after_space'])],
            'decimal_separator' => ['nullable', 'string', 'max:2'],
            'thousands_separator' => ['nullable', 'string', 'max:2'],
            'decimal_precision' => ['nullable', 'integer', 'min:0', 'max:6'],
            'numbering_system' => ['nullable', Rule::in(['short', 'long', 'indian', 'east_asian'])],
            'abbreviations' => ['nullable', 'boolean'],
            // Magnitude at which numbers switch to their compact scale.
            'abbreviation_threshold' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        // Separators must differ or parsing breaks.
        if (
            filled($validated['decimal_separator'] ?? null)
            && filled($validated['thousands_separator'] ?? null)
            && $validated['decimal_separator'] === $validated['thousands_separator']
        ) {
            return back()->with('error', 'Decimal and thousands separators cannot be the same.');
        }

        $institution = Institution::current() ?? new Institution(['name' => 'My Institution']);

        // Resolve the symbol from the chosen currency when none was typed.
        $symbol = $validated['symbol'] ?? null;
        $code = $validated['currency_code'] ?? null;
        if (blank($symbol) && filled($code)) {
            $symbol = Currency::where('code', $code)->value('symbol');
        }

        $settings = array_filter([
            'symbol' => $symbol,
            'symbol_position' => $validated['symbol_position'] ?? null,
            'decimal_separator' => $validated['decimal_separator'] ?? null,
            'thousands_separator' => $validated['thousands_separator'] ?? null,
            'decimal_precision' => $validated['decimal_precision'] ?? null,
            'numbering_system' => $validated['numbering_system'] ?? null,
            'abbreviations' => $validated['abbreviations'] ?? null,
            // Keep an explicit 0 (always abbreviate) - array_filter would drop it.
            'abbreviation_threshold' => $validated['abbreviation_threshold'] ?? null,
        ], fn ($v) => $v !== null);

        $institution->fill([
            'currency_code' => $code,
            'currency_settings' => $settings,
            'is_active' => true,
        ]);
        $institution->save();

        AuditLogger::log('updated', 'updated currency settings for ' . $institution->name, $institution, [
            'currency_code' => $code,
            'settings' => $settings,
        ], ['subject_label' => 'Currency', 'institution_id' => $institution->id]);

        return redirect()->back()->with('success', 'Currency settings saved for this institution.');
    }
}
