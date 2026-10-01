import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { router } from '@inertiajs/react';

/**
 * THE GLOBAL HINT SWITCH.
 *
 * WHY A PROVIDER AND NOT A PROP ON EVERY BADGE
 * --------------------------------------------
 * The hints appear in dozens of places (metric cards, tables, form labels, page
 * intros). Threading an `enabled` prop down to each one would be unmaintainable and
 * would silently miss whichever component was added last. A context means a badge
 * asks ONE question - "are hints on?" - and the answer is the same for every badge
 * on the platform.
 *
 * WHERE THE ANSWER COMES FROM
 * ---------------------------
 * The server (`HandleInertiaRequests` -> the shared `hints` prop), because the
 * preference is stored per ACCOUNT on `user_settings`, not per browser. Signing in
 * on a different machine therefore respects the same choice, which is what a user
 * who turned the hints off actually expects.
 *
 * WHY IT ALSO SUBSCRIBES TO THE ROUTER
 * ------------------------------------
 * This provider renders as a SIBLING of Inertia's `<App>` (see app.jsx), so it
 * cannot call `usePage()` - that hook only exists inside Inertia's context, and
 * calling it from here throws and takes the whole React tree down (the exact
 * blank-page bug documented in LocaleProvider and OnboardingProvider). Instead it
 * listens to the router directly, which is plain JavaScript and works anywhere.
 *
 * The listener is not a nicety: flipping the switch POSTs and then redirects back,
 * so without it the user would have to reload the page manually to see the hints
 * disappear - which reads as "the setting does not work".
 */

const HintsContext = createContext(null);

/** The provider's value when it is not mounted: hints ON, which is the default. */
const FALLBACK = { enabled: true, setEnabled: () => {}, hidden: false };

/**
 * @param {object} props
 * @param {object} props.hints     - the shared `hints` Inertia prop ({ enabled })
 * @param {node}   props.children
 */
export function HintsProvider({ hints = null, children }) {
    const serverEnabled = hints?.enabled ?? true;

    const [enabled, setEnabled] = useState(serverEnabled);

    // Re-sync whenever the server sends a new value, so the state can never drift
    // from what is stored.
    useEffect(() => {
        setEnabled(serverEnabled);
    }, [serverEnabled]);

    // Follow every successful Inertia visit: the toggle's redirect carries the new
    // `hints.enabled`, and this is what applies it without a manual reload.
    useEffect(() => {
        const off = router.on('success', (event) => {
            const next = event?.detail?.page?.props?.hints?.enabled;

            if (typeof next === 'boolean') {
                setEnabled(next);
            }
        });

        return () => {
            // Inertia's router.on returns an unsubscribe function.
            if (typeof off === 'function') off();
        };
    }, []);

    const value = useMemo(
        () => ({
            enabled,
            setEnabled,
            /**
             * The inverse, named for how components READ it. Most call sites guard
             * with `if (hidden) return null;`, which states the intent directly.
             */
            hidden: !enabled,
        }),
        [enabled]
    );

    return <HintsContext.Provider value={value}>{children}</HintsContext.Provider>;
}

/**
 * Read the global hint preference.
 *
 * Returns hints-ON when no provider is mounted, so a hint rendered in isolation
 * (a component test, a surface mounted outside app.jsx) still behaves sensibly
 * instead of crashing on a null context.
 */
export function useHints() {
    return useContext(HintsContext) ?? FALLBACK;
}

/** Convenience: is a hint currently hidden by the user's preference? */
export function useHintsHidden() {
    return useHints().hidden;
}

export default HintsProvider;
