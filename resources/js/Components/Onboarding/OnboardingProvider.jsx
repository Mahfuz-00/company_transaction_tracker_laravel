import React, { useState } from 'react';
import { usePage } from '@inertiajs/react';
import OnboardingModal from './OnboardingModal';

/**
 * FIRST-TIME ONBOARDING HOST.
 *
 * Mounted once in `resources/js/app.jsx`, outside the page layouts, so the guide
 * appears on whatever dashboard the user lands on after their first sign-in -
 * without every dashboard having to remember to render it.
 *
 * The decision of WHETHER to show it, and WHICH journey to show, is made by the
 * server (HandleInertiaRequests -> props.onboarding). This component only owns the
 * "dismissed for this session" flag, so a user who closes the modal is not
 * nagged again until their next sign-in.
 */
export default function OnboardingProvider({ onboarding }) {
    // const { onboarding } = usePage().props;
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