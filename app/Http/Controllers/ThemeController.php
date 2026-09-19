<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The dedicated Theme Customizer.
 *
 * A first-class settings sub-module (moved OUT of Institution Settings) that is
 * reachable by EVERY role - a member personalises their own workspace as freely
 * as an admin does.
 *
 * DUAL PERSISTENCE
 * ----------------
 * The requirement is that a theme follows the person, not the machine:
 *
 *   - PC / browser  : the chosen tokens are written to localStorage instantly,
 *                     so the look persists on THIS device for whoever uses it,
 *                     with no round-trip and no flash.
 *   - Database      : the same tokens are saved to `users.theme`, so signing in
 *                     from any other device re-hydrates the identical theme.
 *
 * The controller is the database half; ThemeProvider.jsx is the localStorage
 * half. Both speak the same token set (mode, accent, radius, density, font), so
 * they can never disagree.
 */
class ThemeController extends Controller
{
    /**
     * Show the customiser with the user's current theme + the options to pick
     * from. Shared props already carry the RESOLVED theme; this view also needs
     * the raw choice so the form starts on the right values.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Settings/ThemeCustomizer', [
            'theme' => $user->themeSettings(),
            // Accent tokens the picker renders as swatches, with their hex.
            'accents' => collect(User::themeAccents())
                ->map(fn ($meta, $key) => [
                    'value' => $key,
                    'label' => $meta['label'],
                    'hex' => $meta['hex'],
                    'soft' => $meta['soft'],
                ])
                ->values(),
            'fonts' => collect(User::THEME_FONTS)
                ->map(fn ($meta, $key) => [
                    'value' => $key,
                    'label' => $meta['label'],
                    'stack' => $meta['stack'],
                ])
                ->values(),
            'radiusOptions' => [
                ['value' => 'sm', 'label' => 'Sharp'],
                ['value' => 'md', 'label' => 'Slightly rounded'],
                ['value' => 'lg', 'label' => 'Rounded'],
                ['value' => 'xl', 'label' => 'Very rounded'],
            ],
            'densityOptions' => [
                ['value' => 'compact', 'label' => 'Compact'],
                ['value' => 'comfortable', 'label' => 'Comfortable'],
                ['value' => 'spacious', 'label' => 'Spacious'],
            ],
        ]);
    }

    /**
     * Persist the chosen theme to the user's profile (the database half).
     *
     * Validation is by WHITELIST: only known tokens and known values are stored,
     * so a crafted payload can never inject arbitrary CSS or an unknown font.
     */
    public function update(Request $request)
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['light', 'dark'])],
            'accent' => ['required', Rule::in(array_keys(Institution::THEMES))],
            'radius' => ['required', Rule::in(['sm', 'md', 'lg', 'xl'])],
            'density' => ['required', Rule::in(['compact', 'comfortable', 'spacious'])],
            'font' => ['required', Rule::in(array_keys(User::THEME_FONTS))],
        ]);

        /** @var User $user */
        $user = $request->user();

        // Merge over the defaults so a future added token always has a value.
        $user->forceFill(['theme' => array_merge(User::DEFAULT_THEME, $data)])->save();

        return redirect()
            ->route('settings.theme.edit')
            ->with('success', 'Theme saved. It will follow you to every device you sign in from.');
    }

    /**
     * Reset to the platform defaults for THIS user (not the institution).
     */
    public function reset(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $user->forceFill(['theme' => null])->save();

        return redirect()
            ->route('settings.theme.edit')
            ->with('success', 'Theme reset to the platform default.');
    }
}
