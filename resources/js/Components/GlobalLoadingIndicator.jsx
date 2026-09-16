import React, { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';

/**
 * A single, centralised loading indicator for the whole application.
 *
 * It listens to Inertia's router events and shows a circular spinner overlay
 * during any async request (page visit, form submit, partial reload).
 *
 * Two behaviours matter for it to feel right:
 *
 *  1. A short delay before showing. Fast requests (< 260ms) resolve before the
 *     indicator appears, so the UI does not flash a spinner for a trivial
 *     navigation.
 *  2. A minimum visible time once shown, so the spinner never blinks in and
 *     out in a single frame on a request that lands right on the threshold.
 */
export default function GlobalLoadingIndicator() {
    const [visible, setVisible] = useState(false);
    const showTimer = useRef(null);
    const hideTimer = useRef(null);

    useEffect(() => {
        const clearTimers = () => {
            clearTimeout(showTimer.current);
            clearTimeout(hideTimer.current);
        };

        const start = () => {
            clearTimers();
            // Wait briefly before surfacing the spinner.
            showTimer.current = setTimeout(() => setVisible(true), 260);
        };

        const finish = () => {
            clearTimers();
            // Hold the spinner for a moment so it does not flicker.
            hideTimer.current = setTimeout(() => setVisible(false), 180);
        };

        const offStart = router.on('start', start);
        const offFinish = router.on('finish', finish);
        const offError = router.on('error', finish);
        const offInvalid = router.on('invalid', finish);

        return () => {
            clearTimers();
            offStart();
            offFinish();
            offError();
            offInvalid();
        };
    }, []);

    if (!visible) return null;

    return (
        <div
            className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/25 backdrop-blur-[2px] transition-opacity duration-200 animate-in fade-in"
            role="alertdialog"
            aria-busy="true"
            aria-live="polite"
            aria-label="Loading"
        >
            <div className="flex flex-col items-center gap-3 rounded-2xl border-slate-100 bg-white/95 px-8 py-6 shadow-xl">
                {/* Circular spinner, themed via --accent. */}
                <svg
                    className="h-9 w-9 animate-spin text-[var(--accent,#4f46e5)]"
                    viewBox="0 0 24 24"
                    fill="none"
                    aria-hidden="true"
                >
                    <circle cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="3.5" className="opacity-20" />
                    <path
                        d="M12 2a10 10 0 019.54 7"
                        stroke="currentColor"
                        strokeWidth="3.5"
                        strokeLinecap="round"
                    />
                </svg>
                <p className="text-sm font-semibold text-slate-600">Loading…</p>
            </div>
        </div>
    );
}
