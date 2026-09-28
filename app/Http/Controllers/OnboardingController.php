<?php

namespace App\Http\Controllers;

use App\Support\OnboardingGuide;
use Illuminate\Http\Request;

/**
 * ROLE-SPECIFIC FIRST-TIME ONBOARDING.
 *
 * Two tiny endpoints back the first-login manual:
 *
 *   POST /onboarding/complete  - marks the tour as seen, so it never reappears.
 *   POST /onboarding/replay    - (optional) re-opens it from the Profile Manager.
 *
 * The CONTENT is served through the shared Inertia props (HandleInertiaRequests)
 * rather than fetched, so the modal is ready on the very first paint with no
 * extra round-trip and no loading flicker. This controller only owns the STATE.
 */
class OnboardingController extends Controller
{
    /**
     * Mark the signed-in user's onboarding as complete.
     *
     * Idempotent: completing it twice is harmless, and we never overwrite an
     * existing timestamp (so the "first completion" date stays truthful).
     */
    public function complete(Request $request)
    {
        $user = $request->user();

        if ($user && ! $user->hasCompletedOnboarding()) {
            $user->forceFill(['onboarding_completed_at' => now()])->save();
        }

        // The modal closes itself on success; a redirect-back keeps Inertia's
        // page props (including the now-updated `onboarding.show`) in sync.
        return back()->with('success', 'Welcome aboard! You can revisit this guide any time from your profile.');
    }

    /**
     * Re-open the guide on demand (from the Profile Manager / help link).
     *
     * Clearing the completion flag is enough: the shared Inertia prop
     * (`onboarding.show`) re-evaluates on the next response, so the modal appears
     * again for a deliberate replay.
     */
    public function replay(Request $request)
    {
        $user = $request->user();

        if ($user && $user->hasCompletedOnboarding()) {
            $user->forceFill(['onboarding_completed_at' => null])->save();
        }

        return back()->with('success', 'Here is your guide again.');
    }

    /**
     * The role-specific guide content, as JSON.
     *
     * Exposed for clients (and the Dusk suite) that want to assert the journey
     * steps for a given role without rendering the modal.
     */
    public function show(Request $request)
    {
        $user = $request->user();

        abort_unless($user !== null, 403);

        return response()->json([
            'role' => $user->onboardingRole(),
            'guide' => OnboardingGuide::for($user),
        ]);
    }
}
