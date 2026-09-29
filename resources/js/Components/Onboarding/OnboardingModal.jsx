import React, { useEffect, useMemo, useState } from 'react';
import { router, usePage } from '@inertiajs/react';

/**
 * ROLE-SPECIFIC FIRST-TIME ONBOARDING MODAL.
 *
 * Shown ONCE, on a user's very first sign-in, and tailored to their user type:
 *
 *   - SSA (Software Super Admin) - the whole platform / SaaS business.
 *   - IA  (Institution Admin)    - one workspace's people, meals and money.
 *   - MM  (Meal Manager)         - the members assigned to them.
 *   - Member                     - their own personal meals and balance.
 *
 * CONTENT comes from the server (`props.onboarding.guide`, see OnboardingGuide)
 * so the journey steps live in ONE place and cannot drift between the backend and
 * this UI. `props.onboarding.show` is true only until the guide is completed.
 *
 * PROPS
 *   - `guide`    : { role, title, subtitle, accent, steps[], first_action }
 *   - `open`     : whether the modal is currently visible (parent-controlled).
 *   - `onClose`  : close handler (dismiss == complete).
 *
 * Inertia / React concepts on show:
 *   - `router.post(route('onboarding.complete'))` records completion server-side,
 *     so the modal does not return on the next page load or another device.
 *   - `useState` tracks the current step for the multi-step walkthrough.
 */

/* ------------------------------------------------------------------ *
 * Icons - a tiny inline set so the modal has no icon-library dependency.
 * ------------------------------------------------------------------ */

const ICONS = {
    chart: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
    building: 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
    users: 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z',
    shield: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
    cog: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z',
    utensils: 'M3 3v7a3 3 0 003 3v8m0-11V3m0 7h3m-3 0H3m6-7v18m6-18v7c0 1.657 1.343 3 3 3h0V3m-3 0v7',
    mail: 'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
    document: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
    cash: 'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z',
    clipboard: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4',
};

/** Accent tone map, keyed by the guide's `accent` value. */
const TONES = {
    amber: { ring: 'border-amber-200', chip: 'bg-amber-100 text-amber-700', bar: 'bg-amber-500' },
    indigo: { ring: 'border-indigo-200', chip: 'bg-indigo-100 text-indigo-700', bar: 'bg-indigo-500' },
    emerald: { ring: 'border-emerald-200', chip: 'bg-emerald-100 text-emerald-700', bar: 'bg-emerald-500' },
    sky: { ring: 'border-sky-200', chip: 'bg-sky-100 text-sky-700', bar: 'bg-sky-500' },
};

function StepIcon({ name, className = 'h-5 w-5' }) {
    return (
        <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" d={ICONS[name] || ICONS.chart} />
        </svg>
    );
}

export default function OnboardingModal({ guide = null, open = false, onClose = () => { }, recordCompletion = true }) {
    const [step, setStep] = useState(0);
    const [saving, setSaving] = useState(false);

    const steps = guide?.steps || [];
    const total = steps.length;

    // Reset to the first step each time the modal opens.
    useEffect(() => {
        if (open) setStep(0);
    }, [open]);

    // Close on Escape for keyboard users.
    useEffect(() => {
        if (!open) return undefined;
        const onKey = (e) => { if (e.key === 'Escape') onClose(); };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open, onClose]);

    const tone = useMemo(() => TONES[guide?.accent] || TONES.indigo, [guide?.accent]);

    if (!open || !guide || total === 0) return null;

    const isLast = step === total - 1;
    const current = steps[step];

    /**
     * Finish the walkthrough.
     *
     * `recordCompletion` distinguishes the two ways this modal is opened:
     *
     *   - FIRST LOGIN (true): the server handed us the guide because the account
     *     has never seen it. Finishing must record that fact, or the tour returns
     *     on every page load.
     *   - A DELIBERATE REPLAY (false): the account has ALREADY completed the guide
     *     and asked to see it again. There is nothing to record, so no request is
     *     sent - which is what makes "Show me around" an instant, in-place action
     *     with no redirect instead of a round-trip that navigates away (and used to
     *     bounce the user to their profile page).
     */
    const finish = () => {
        if (!recordCompletion) {
            onClose();
            return;
        }

        setSaving(true);
        router.post(route('onboarding.complete'), {}, {
            preserveScroll: true,
            preserveState: false,
            onFinish: () => {
                setSaving(false);
                onClose();
            },
        });
    };

    return (
        <div
            className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-sm"
            role="dialog"
            aria-modal="true"
            aria-labelledby="onboarding-title"
            data-testid="onboarding-modal"
        >
            <div className="flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
                {/* Header */}
                <div className={`border-b px-6 py-5 ${tone.ring}`}>
                    <div className="flex items-start justify-between gap-4">
                        <div className="min-w-0">
                            <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider ${tone.chip}`}>
                                {guide.role === 'ssa' ? 'Software Super Admin'
                                    : guide.role === 'ia' ? 'Institution Admin'
                                        : guide.role === 'mm' ? 'Meal Manager'
                                            : 'Member'}
                            </span>
                            <h3 id="onboarding-title" className="mt-2 text-lg font-bold text-slate-900">
                                {guide.title}
                            </h3>
                            <p className="mt-1 text-sm text-slate-500">{guide.subtitle}</p>
                        </div>
                        <button
                            type="button"
                            onClick={onClose}
                            data-testid="onboarding-skip"
                            className="flex-shrink-0 rounded-lg p-1 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600"
                            aria-label="Close onboarding"
                        >
                            <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    {/* Progress bar */}
                    <div className="mt-4 flex gap-1.5" aria-hidden="true">
                        {steps.map((_, i) => (
                            <span
                                key={i}
                                className={`h-1 flex-1 rounded-full transition-colors ${i <= step ? tone.bar : 'bg-slate-200'}`}
                            />
                        ))}
                    </div>
                </div>

                {/* Body */}
                <div className="flex-1 overflow-y-auto px-6 py-6">
                    <div className="flex gap-4">
                        <span className={`flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl ${tone.chip}`}>
                            <StepIcon name={current.icon} />
                        </span>
                        <div className="min-w-0">
                            <h4
                                className="text-base font-bold text-slate-900"
                                data-testid={`onboarding-step-${step}`}
                            >
                                {current.title}
                            </h4>
                            <p className="mt-1.5 text-sm leading-relaxed text-slate-600">
                                {current.body}
                            </p>
                        </div>
                    </div>

                    {/* Step counter + list of all steps for orientation */}
                    <ul className="mt-6 space-y-2">
                        {steps.map((s, i) => (
                            <li key={i}>
                                <button
                                    type="button"
                                    onClick={() => setStep(i)}
                                    className={`flex w-full items-center gap-3 rounded-xl border px-3.5 py-2.5 text-left text-sm transition-all ${i === step
                                        ? 'border-slate-300 bg-slate-50 font-semibold text-slate-900'
                                        : 'border-transparent text-slate-500 hover:bg-slate-50'}`}
                                >
                                    <span className={`flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full text-[11px] font-bold ${i <= step ? tone.chip : 'bg-slate-100 text-slate-400'}`}>
                                        {i + 1}
                                    </span>
                                    {s.title}
                                </button>
                            </li>
                        ))}
                    </ul>

                    {/* First action callout */}
                    {guide.first_action && (
                        <div className="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                Your first action
                            </p>
                            <p className="mt-1 text-sm font-semibold text-slate-800">
                                {guide.first_action.label}
                            </p>
                            <p className="mt-0.5 text-xs text-slate-500">{guide.first_action.hint}</p>
                        </div>
                    )}
                </div>

                {/* Footer */}
                <div className="flex items-center justify-between gap-3 border-t border-slate-100 bg-slate-50 px-6 py-4">
                    <span className="text-xs font-medium text-slate-400">
                        Step {step + 1} of {total}
                    </span>

                    <div className="flex items-center gap-2">
                        {step > 0 && (
                            <button
                                type="button"
                                onClick={() => setStep((s) => Math.max(0, s - 1))}
                                className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-100"
                            >
                                Back
                            </button>
                        )}

                        {!isLast ? (
                            <button
                                type="button"
                                onClick={() => setStep((s) => Math.min(total - 1, s + 1))}
                                data-testid="onboarding-next"
                                className="rounded-lg bg-slate-900 px-5 py-2 text-sm font-semibold text-white transition-opacity hover:opacity-90"
                            >
                                Next
                            </button>
                        ) : (
                            <button
                                type="button"
                                onClick={finish}
                                disabled={saving}
                                data-testid="onboarding-finish"
                                className="rounded-lg bg-slate-900 px-5 py-2 text-sm font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-50"
                            >
                                {saving ? 'Finishing...' : 'Get started'}
                            </button>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}