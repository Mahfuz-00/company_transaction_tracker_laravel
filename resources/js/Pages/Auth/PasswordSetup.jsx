import React, { useMemo, useState } from 'react';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';

/**
 * Password setup / reset screen.
 *
 * Reached from a signed invitation or reset link. Two modes, decided server-side
 * by whether the account already exists:
 *   - create : a first-time invitee. They confirm their name and choose a
 *              password - the account is created at submit time.
 *   - reset  : the account already exists; they simply choose a new password.
 *
 * Design notes:
 *   - a live strength meter gives immediate feedback,
 *   - the submit button shows a smooth inline spinner while processing,
 *   - errors surface inline per field (no alerts).
 */

function StrengthMeter({ value }) {
    const { score, label, tone } = useMemo(() => {
        if (!value) return { score: 0, label: 'Enter a password', tone: 'bg-slate-200' };

        let points = 0;
        if (value.length >= 8) points++;
        if (value.length >= 12) points++;
        if (/[A-Z]/.test(value) && /[a-z]/.test(value)) points++;
        if (/\d/.test(value)) points++;
        if (/[^A-Za-z0-9]/.test(value)) points++;

        const capped = Math.min(points, 4);
        const meta = [
            { label: 'Too weak', tone: 'bg-rose-500' },
            { label: 'Weak', tone: 'bg-orange-500' },
            { label: 'Fair', tone: 'bg-amber-500' },
            { label: 'Strong', tone: 'bg-emerald-500' },
        ][Math.min(capped, 3)];

        return { score: capped >= 1 ? capped : 1, ...meta };
    }, [value]);

    const widthClass = {
        0: 'w-0',
        1: 'w-1/4',
        2: 'w-2/4',
        3: 'w-3/4',
        4: 'w-full',
    }[score];

    return (
        <div className="mt-2">
            <div className="h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                <div className={`h-full rounded-full transition-all duration-300 ${score >= 1 ? tone : 'bg-slate-200'} ${widthClass}`} />
            </div>
            <p className="mt-1 text-[11px] font-medium text-slate-400">{label}</p>
        </div>
    );
}

export default function PasswordSetup({
    invitation,
    token,
    institutionName = null,
    mode = 'create',
    prefillName = false,
}) {
    const isReset = mode === 'reset';

    const { data, setData, post, processing, errors, reset } = useForm({
        token: token || '',
        // Always seed the field with whatever name we have (the real user's name,
        // the invited name, or blank), so it is correctable in both modes.
        name: invitation?.name || '',
        password: '',
        password_confirmation: '',
    });

    const [showPassword, setShowPassword] = useState(false);
    const mismatch = data.password_confirmation.length > 0 && data.password !== data.password_confirmation;

    const submit = (e) => {
        e.preventDefault();
        if (mismatch) return;

        post(route('password.setup.store', invitation.id), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    const inputClass = (hasError) =>
        `w-full rounded-lg border px-3.5 py-2.5 text-sm text-slate-900 outline-none transition-all placeholder:text-slate-400 focus:ring-2 ${hasError
            ? 'border-rose-300 focus:border-rose-500 focus:ring-rose-500/20'
            : 'border-slate-300 focus:border-[var(--accent)] focus:ring-[var(--accent-ring)]'
        }`;

    return (
        <GuestLayout>
            <Head title={isReset ? 'Set a new password' : 'Set up your account'} />

            <div className="mb-6">
                <span className="inline-flex items-center gap-2 rounded-full border-indigo-100 bg-indigo-50 px-3 py-1 text-[11px] font-semibold text-indigo-700">
                    <span className="h-1.5 w-1.5 rounded-full bg-indigo-500" />
                    {isReset ? 'Password reset' : 'Account setup'}
                </span>

                <h2 className="mt-4 text-xl font-bold tracking-tight text-slate-900">
                    {isReset ? 'Set a new password' : 'Welcome - set your password'}
                </h2>
                <p className="mt-1.5 text-sm leading-relaxed text-slate-500">
                    {isReset ? (
                        <>Choose a new password for <strong className="font-semibold text-slate-700">{invitation?.email}</strong>.</>
                    ) : (
                        <>
                            {institutionName ? <><strong className="font-semibold text-slate-700">{institutionName}</strong> invited you. </> : null}
                            Finish setting up <strong className="font-semibold text-slate-700">{invitation?.email}</strong> by choosing a password.
                        </>
                    )}
                </p>
            </div>

            <form onSubmit={submit} className="space-y-5">
                {/*
                 * The name field is ALWAYS shown and editable - in both the
                 * first-time and reset modes - so a placeholder name can be
                 * corrected during setup. It is never auto-submitted back if the
                 * user does not want to change it (it just carries the prefilled
                 * value through).
                 */}
                <div>
                    <label htmlFor="name" className="mb-1.5 block text-sm font-semibold text-slate-700">
                        Your full name
                        <span className="ml-1 font-normal text-slate-400">(you can correct this)</span>
                    </label>
                    <input
                        id="name"
                        type="text"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        autoComplete="name"
                        autoFocus
                        placeholder="e.g. Farhan Hossain"
                        className={inputClass(errors.name)}
                    />
                    {errors.name && <p className="mt-1 text-xs text-rose-500">{errors.name}</p>}
                </div>

                <div>
                    <label htmlFor="password" className="mb-1.5 block text-sm font-semibold text-slate-700">
                        New password
                    </label>
                    <div className="relative">
                        <input
                            id="password"
                            type={showPassword ? 'text' : 'password'}
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            autoComplete="new-password"
                            placeholder="At least 8 characters"
                            className={`${inputClass(errors.password)} pr-12`}
                        />
                        <button
                            type="button"
                            onClick={() => setShowPassword((v) => !v)}
                            aria-label={showPassword ? 'Hide password' : 'Show password'}
                            className="absolute right-1.5 top-1/2 -translate-y-1/2 rounded-md p-2 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600"
                        >
                            <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                {showPassword ? (
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                                ) : (
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                )}
                            </svg>
                        </button>
                    </div>
                    {errors.password ? (
                        <p className="mt-1 text-xs text-rose-500">{errors.password}</p>
                    ) : (
                        <StrengthMeter value={data.password} />
                    )}
                </div>

                <div>
                    <label htmlFor="password_confirmation" className="mb-1.5 block text-sm font-semibold text-slate-700">
                        Confirm password
                    </label>
                    <input
                        id="password_confirmation"
                        type={showPassword ? 'text' : 'password'}
                        value={data.password_confirmation}
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                        autoComplete="new-password"
                        placeholder="Repeat your password"
                        className={inputClass(mismatch || errors.password_confirmation)}
                    />
                    {mismatch ? (
                        <p className="mt-1 text-xs text-rose-500">Passwords do not match.</p>
                    ) : errors.password_confirmation ? (
                        <p className="mt-1 text-xs text-rose-500">{errors.password_confirmation}</p>
                    ) : null}
                </div>

                <button
                    type="submit"
                    disabled={processing || mismatch || !data.password}
                    className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[var(--accent)] px-6 py-3 text-sm font-bold text-white shadow-lg shadow-[var(--accent-ring)] transition-all hover:opacity-90 active:opacity-100 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {processing && (
                        <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" className="opacity-25" />
                            <path d="M4 12a8 8 0 018-8" stroke="currentColor" strokeWidth="4" strokeLinecap="round" className="opacity-90" />
                        </svg>
                    )}
                    {processing
                        ? 'Saving...'
                        : isReset
                            ? 'Update my password'
                            : 'Activate my account'}
                </button>

                <p className="text-center text-xs text-slate-400">
                    Already activated?{' '}
                    <Link href={route('login')} className="font-semibold text-[var(--accent)] hover:underline">
                        Sign in
                    </Link>
                </p>
            </form>
        </GuestLayout>
    );
}
