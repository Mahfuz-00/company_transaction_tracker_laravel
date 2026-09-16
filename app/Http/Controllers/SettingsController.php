<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\Institution;
use App\Models\UserSetting;
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
    public function index(Request $request)
    {
        $user = $request->user();
        $institution = Institution::current();

        return Inertia::render('Settings/CurrencyManager', [
            'currencies' => Currency::orderBy('name')->get(),
            'currencySettings' => $institution?->currencySettings()
                ?? Institution::DEFAULT_CURRENCY_SETTINGS,
            'canManage' => $user->isSuperAdmin(),
            // Kept for backwards compatibility with any older widget.
            'userSettings' => UserSetting::where('user_id', $user->id)->first()?->settings,
        ]);
    }

    public function store(Request $request)
    {
        // Only the Software Super Admin may change global formatting.
        if (! $request->user()->isSuperAdmin()) {
            return back()->with('error', 'Only a Software Super Admin can change global currency settings.');
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
        ], fn ($v) => $v !== null);

        $institution->fill([
            'currency_code' => $code,
            'currency_settings' => $settings,
            'is_active' => true,
        ]);
        $institution->save();

        AuditLogger::log('updated', 'updated global currency settings', $institution, [
            'currency_code' => $code,
            'settings' => $settings,
        ], ['subject_label' => 'Global Currency']);

        return redirect()->back()->with('success', 'Global currency settings saved.');
    }
}