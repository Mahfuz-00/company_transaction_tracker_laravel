<?php

namespace App\Http\Controllers;

use App\Support\AuditLogger;
use Illuminate\Http\Request;

/**
 * THE USER'S OWN PERSONAL PREFERENCES.
 *
 * WHAT BELONGS HERE
 * -----------------
 * Preferences that describe how ONE person wants the product to behave for them,
 * and that no administrator should be able to override:
 *
 *   - `hints_enabled` : whether the in-body `?` hints and their popovers are shown
 *                       anywhere on the platform.
 *
 * WHAT DOES NOT BELONG HERE
 * -------------------------
 * Anything that changes what the platform DOES for others - currency formatting,
 * a seat's role, the institution's theme default. Those are workspace settings and
 * live in their own controllers, because a personal preference must never be able
 * to alter another user's data.
 *
 * NO PERMISSION GATE, DELIBERATELY
 * --------------------------------
 * Every role - member included - may choose whether to see their own hints. There
 * is nothing privileged about it, exactly like the theme customiser. The only rules
 * are that the payload must be a boolean and that the write is scoped to
 * `$request->user()`, which is what stops a crafted request from editing someone
 * else's settings.
 */
class UserPreferenceController extends Controller
{
    /**
     * POST /settings/hints
     *
     * Turn the platform-wide hint preference on or off for the signed-in user.
     *
     * Modeled as a REQUIRED boolean rather than a toggle: an explicit "on" and an
     * explicit "off" both arrive with the value they intend, so a double-submitted
     * request (a slow network, an impatient double-click) cannot flip the setting
     * back the other way - the failure mode of a blind toggle.
     */
    public function updateHints(Request $request)
    {
        $data = $request->validate([
            'hints_enabled' => ['required', 'boolean'],
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();

        $enabled = (bool) $data['hints_enabled'];

        $user->setHintsEnabled($enabled);

        /*
         * Audit it. This looks trivial from the inside, but it is the only way to
         * answer two support questions that genuinely get asked:
         * "why can't I see the tips?" and "who turned the guidance off?".
         */
        AuditLogger::log(
            'updated',
            $enabled ? 'turned help hints on' : 'turned help hints off',
            $user,
            ['hints_enabled' => $enabled],
            ['subject_label' => $user->name, 'institution_id' => $user->institution_id]
        );

        return back()->with(
            'success',
            $enabled
                ? 'Help hints are on — look for the ? badges beside each figure.'
                : 'Help hints are off. You can turn them back on here at any time.'
        );
    }
}
