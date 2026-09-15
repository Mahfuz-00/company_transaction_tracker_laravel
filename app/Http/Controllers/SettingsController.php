<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\UserSetting;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SettingsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $currencies = Currency::orderBy('name')->get();
        $userSettings = UserSetting::where('user_id', $user->id)->first();

        return Inertia::render('Settings', [
            'currencies' => $currencies,
            'userSettings' => $userSettings ? $userSettings->settings : null,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'currency_code' => 'nullable|string',
            'symbol_position' => 'nullable|string',
            'decimal_separator' => 'nullable|string',
            'thousands_separator' => 'nullable|string',
            'decimal_precision' => 'nullable|integer|min:0',
            'numbering_system' => 'nullable|string',
            'abbreviations' => 'nullable|boolean',
        ]);

        UserSetting::updateOrCreate(
            ['user_id' => $user->id],
            ['settings' => $validated]
        );

        return redirect()->back()->with('success', 'Settings saved.');
    }
}
