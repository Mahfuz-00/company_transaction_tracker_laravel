<?php

namespace App\Http\Controllers;

use App\Support\LocaleManager;
use Illuminate\Http\Request;

/**
 * LANGUAGE SWITCHING.
 *
 *   POST /language          — set the active locale
 *
 * PERMISSION
 *   Any authenticated user, and any guest: choosing a language is a personal
 *   preference, not an administrative act. There is therefore no permission gate —
 *   the only rule is that the submitted code must be one the platform ships
 *   (enforced by LocaleManager::persist, so a crafted request cannot write an
 *   arbitrary value onto the user row).
 */
class LanguageController extends Controller
{
    /** Switch the active language. */
    public function update(Request $request)
    {
        $data = $request->validate([
            'locale' => ['required', 'string', 'max:10'],
        ]);

        if (! LocaleManager::persist($data['locale'])) {
            return back()->with('error', 'That language is not available.');
        }

        // Apply immediately so the redirect renders in the NEW language rather
        // than the old one until the next request.
        LocaleManager::apply($data['locale']);

        return back()->with('success', __('app.language.saved'));
    }

    /**
     * Set the locale for an anonymous visitor.
     *
     * A guest has no `users` row to persist to, so this writes the session only.
     * Kept as a GET so the landing page's switcher can be a plain link — there is
     * nothing destructive about picking a language.
     */
    public function setGuest(Request $request, string $locale)
    {
        if (! LocaleManager::isSupported($locale)) {
            return redirect()->back();
        }

        $request->session()->put(LocaleManager::SESSION_KEY, $locale);

        LocaleManager::apply($locale);

        return redirect()->back();
    }
}
