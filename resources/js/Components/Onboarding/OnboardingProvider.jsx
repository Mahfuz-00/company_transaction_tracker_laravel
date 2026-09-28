import React, { useState } from 'react';
import OnboardingModal from './OnboardingModal';

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
    const [dismissed, setDismissed] = useState(false);

    if (!onboarding || !onboarding.show || !onboarding.guide) return null;

    return (
        <OnboardingModal
            guide={onboarding.guide}
            open={!dismissed}
            onClose={() => setDismissed(true)}
        />
    );
}