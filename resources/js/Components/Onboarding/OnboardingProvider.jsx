import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import OnboardingModal from './OnboardingModal';

/**
 * THE TOUR CONTEXT.
 *
 * Two ways to open the guide, and they are deliberately separate:
 *
 *   1. AUTOMATIC — the server says this is a first login (props.onboarding.show),
 *      so the tour opens on its own, exactly once.
 *   2. ON DEMAND — any screen calls `openTour()`, which is what the profile menu's
 *      "Replay the tour" and the profile page's button do.
 *
 * The second is NOT a server round-trip. An earlier version posted to
 * `/onboarding/replay`, which cleared the completion flag and redirected `back()`,
 * so the user was bounced to the page they came from (in practice, their profile
 * settings) with no guide visible — because the modal only ever reads the guide
 * from the FIRST page of the session, and an Inertia visit does not re-render it.
 * Opening locally is instant, keeps the user exactly where they were, and cannot
 * lose the request.
 */
const TourContext = createContext(null);

/** What components get when no host is mounted: no tour, and no crash. */
const FALLBACK = { openTour: () => { }, isOpen: false, guide: null, canOpen: false };

/**
 * FIRST-TIME ONBOARDING HOST.
 *
 * Mounted once in `resources/js/app.jsx`, outside the page layouts, so the guide
 * appears on whatever dashboard the user lands on after their first sign-in —
 * without every dashboard having to remember to render it.
 *
 * The decision of WHETHER to show it, and WHICH journey to show, is made by the
 * server (`HandleInertiaRequests` → `props.onboarding`). This component only owns
 * the "dismissed for this session" flag, so a user who closes the modal is not
 * nagged again until their next sign-in.
 *
 * WHY IT TAKES A PROP AND DOES NOT CALL `usePage()`
 * -------------------------------------------------
 * Two failed attempts preceded this, and both are worth recording because the
 * failure mode is silent:
 *
 *   1. The component required an `onboarding` PROP, but `app.jsx` mounted it with
 *      no props while the `usePage()` line stayed commented out. So `onboarding`
 *      was permanently `undefined`, the guard below returned `null` on every
 *      render, and the first-login tour NEVER appeared — while every other part of
 *      the feature (the server prop, the guide content, the modal markup) looked
 *      perfectly correct.
 *
 *   2. "Fixing" that by calling `usePage()` here was WRONG, and broke the entire
 *      app. Inertia provides its context INSIDE `<App>`. This component renders as
 *      a SIBLING of `<App>`, so it sits outside that provider and `usePage()`
 *      threw "usePage must be used within the Inertia component" — taking the
 *      whole React tree down and rendering a blank page on every route.
 *
 * Taking the payload as an explicit prop makes this a plain React component with
 * no framework-context dependency, so it works anywhere it is mounted. `app.jsx`
 * reads `initialPage.props.onboarding` once and passes it down.
 *
 * @param {object}  props
 * @param {object} [props.onboarding] - `{ show, guide }` from the shared Inertia props
 */
export default function OnboardingProvider({ onboarding = null }) {
    /*
     * The guide CONTENT is present for every signed-in user; only `show` is
     * conditional on a first login. That is precisely what makes an on-demand
     * replay possible with no request: the steps are already in the payload.
     */
    const guide = onboarding?.guide ?? null;
    const firstLogin = Boolean(onboarding?.show && guide);

    // The automatic first-login tour, dismissible for this session.
    const [autoOpen, setAutoOpen] = useState(firstLogin);

    // A deliberate replay, kept SEPARATE from `autoOpen` so dismissing the
    // first-login tour can never make a later replay impossible.
    const [manualOpen, setManualOpen] = useState(false);

    // The server is the authority on first-login state; re-sync on every visit so
    // completing the tour (which triggers a redirect) closes it for good.
    useEffect(() => {
        setAutoOpen(firstLogin);
    }, [firstLogin]);

    const openTour = useCallback(() => setManualOpen(true), []);

    const close = useCallback(() => {
        setAutoOpen(false);
        setManualOpen(false);
    }, []);

    const isOpen = manualOpen || autoOpen;

    const value = useMemo(
        () => ({ openTour, isOpen, guide, canOpen: Boolean(guide) }),
        [openTour, isOpen, guide]
    );

    return (
        <TourContext.Provider value={value}>
            {guide && isOpen && (
                <OnboardingModal
                    guide={guide}
                    open
                    onClose={close}
                    /*
                     * Only an AUTOMATIC first-login view records completion. A
                     * replay has nothing to record, so it sends no request at all —
                     * see OnboardingModal::finish().
                     */
                    recordCompletion={autoOpen && firstLogin}
                />
            )}
        </TourContext.Provider>
    );
}

/**
 * The tour API, for any component that wants to offer "Show me around".
 *
 *   const { openTour, canOpen } = useTour();
 *   <button onClick={openTour}>Show me around</button>
 *
 * Safe outside the provider (it returns a no-op), so a page can render the trigger
 * without knowing whether the tour host happens to be mounted.
 */
export function useTour() {
    return useContext(TourContext) ?? FALLBACK;
}