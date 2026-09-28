import React from 'react';

/**
 * SSO BUTTONS — "Sign in with Google / Microsoft / Facebook / X".
 *
 * TWO RESPONSIBILITIES
 * --------------------
 *   1. Render a button per provider the server has configured (client id + secret).
 *      An unconfigured provider shows nothing rather than a dead button.
 *   2. THE INVITE-CODE GATE. When `requireInviteCode` is set, the buttons stay
 *      disabled until the code has been CONFIRMED. There are two modes:
 *        - `inviteCodeValidated={true}` (preferred): the caller has already
 *          verified the code against the server (POST
 *          /register/validate-invite-code) — the real gate.
 *        - `inviteCodeValidated={undefined}`: no server check was performed, so
 *          the component falls back to a MINIMUM-LENGTH heuristic purely to keep
 *          the login page usable. Prefer the validated mode wherever a server
 *          round-trip is available.
 *      Either way the code is appended to our own `/auth/{provider}/redirect`
 *      URL, so the user is routed into the right workspace BEFORE the OAuth
 *      round-trip begins — the server validates it there and fails fast on an
 *      unknown code rather than sending the user through the provider for nothing.
 *
 * The button links to OUR redirect endpoint (never straight to the provider),
 * because that is where the CSRF `state` is minted and stored.
 *
 * @param {object} props
 * @param {Array}  props.providers          - [{ provider, label, short, icon, redirect_url }]
 * @param {string} [props.subtitle]         - small line above the buttons
 * @param {boolean} [props.requireInviteCode] - gate the buttons behind an invite code
 * @param {string} [props.inviteCode]       - the code entered so far
 * @param {boolean} [props.inviteCodeValidated] - the code was confirmed server-side
 * @param {string} [props.note]             - optional helper line
 */
export default function SsoButtons({
    providers = [],
    subtitle = null,
    requireInviteCode = false,
    inviteCode = '',
    inviteCodeValidated,
    note = null,
}) {
    if (!providers || providers.length === 0) return null;

    /*
     * THE GATE.
     *
     * When the caller supplies a `inviteCodeValidated` boolean, that SERVER
     * VERDICT is the gate — the local length is irrelevant (a confirmed code is
     * confirmed, however short). Only when no server check exists at all do we
     * fall back to the length heuristic, so the login page (which has no
     * validation round-trip) still behaves sensibly.
     */
    const codeReady = !requireInviteCode
        ? true
        : inviteCodeValidated !== undefined
            ? Boolean(inviteCodeValidated)
            : inviteCode.trim().length >= 4;

    /** Append the invite code so the server can pin the workspace. */
    const hrefFor = (entry) => {
        const code = inviteCode.trim();

        if (!code) return entry.redirect_url;

        const separator = entry.redirect_url.includes('?') ? '&' : '?';

        return `${entry.redirect_url}${separator}invite_code=${encodeURIComponent(code)}`;
    };

    return (
        <div data-testid="sso-buttons" className="mt-6">
            {/* Divider with the subtitle in the middle. */}
            <div className="relative">
                <div className="absolute inset-0 flex items-center" aria-hidden="true">
                    <div className="w-full border-t border-slate-200" />
                </div>
                <div className="relative flex justify-center">
                    <span className="bg-white px-3 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                        {subtitle || 'Or continue with'}
                    </span>
                </div>
            </div>

            {note && (
                <p className="mt-3 text-center text-[11px] leading-relaxed text-slate-500">{note}</p>
            )}

            {/* The gate explanation: shown only while the code is not yet confirmed. */}
            {requireInviteCode && !codeReady && (
                <p
                    data-testid="sso-invite-hint"
                    className="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-center text-[11px] font-medium text-amber-800"
                >
                    {inviteCodeValidated !== undefined
                        ? 'Enter a valid institution invite code above to unlock these options - it routes your sign-in to the right workspace.'
                        : 'Enter your institution invite code above first - it routes your sign-in to the right workspace.'}
                </p>
            )}

            <div className="mt-4 grid gap-2.5 sm:grid-cols-2">
                {providers.map((entry) => {
                    const disabled = !codeReady;

                    return (
                        <a
                            key={entry.provider}
                            href={disabled ? undefined : hrefFor(entry)}
                            data-testid={`sso-button-${entry.provider}`}
                            aria-disabled={disabled}
                            aria-label={`Sign in with ${entry.label}`}
                            onClick={(e) => { if (disabled) e.preventDefault(); }}
                            className={`inline-flex w-full items-center justify-center gap-2.5 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-xs transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 ${disabled
                                ? 'cursor-not-allowed opacity-50'
                                : 'hover:border-slate-400 hover:bg-slate-50'}`}
                        >
                            <ProviderIcon icon={entry.icon} />
                            <span className="truncate">{entry.short}</span>
                        </a>
                    );
                })}
            </div>
        </div>
    );
}

/** The provider mark, inlined so there is no external asset dependency. */
function ProviderIcon({ icon }) {
    if (icon === 'google') {
        return (
            <svg className="h-4 w-4 flex-shrink-0" viewBox="0 0 24 24" aria-hidden="true">
                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" />
                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0012 23z" />
                <path fill="#FBBC05" d="M5.84 14.09a6.6 6.6 0 010-4.18V7.07H2.18a11 11 0 000 9.86l3.66-2.84z" />
                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1a11 11 0 00-9.82 6.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" />
            </svg>
        );
    }

    if (icon === 'microsoft') {
        return (
            <svg className="h-4 w-4 flex-shrink-0" viewBox="0 0 24 24" aria-hidden="true">
                <path fill="#F25022" d="M2 2h9.5v9.5H2z" />
                <path fill="#7FBA00" d="M12.5 2H22v9.5h-9.5z" />
                <path fill="#00A4EF" d="M2 12.5h9.5V22H2z" />
                <path fill="#FFB900" d="M12.5 12.5H22V22h-9.5z" />
            </svg>
        );
    }

    if (icon === 'facebook') {
        return (
            <svg className="h-4 w-4 flex-shrink-0" viewBox="0 0 24 24" aria-hidden="true">
                <path fill="#1877F2" d="M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.1 10.13 24v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.69 4.53-4.69 1.31 0 2.68.24 2.68.24v2.97h-1.51c-1.49 0-1.96.93-1.96 1.89v2.25h3.33l-.53 3.49h-2.8V24C19.61 23.1 24 18.1 24 12.07z" />
            </svg>
        );
    }

    if (icon === 'x') {
        return (
            <svg className="h-4 w-4 flex-shrink-0" viewBox="0 0 24 24" aria-hidden="true">
                <path fill="#000000" d="M18.9 1.15h3.68l-8.04 9.19L24 22.85h-7.41l-5.8-7.58-6.64 7.58H.46l8.6-9.83L0 1.15h7.6l5.24 6.93 6.06-6.93zm-1.29 19.5h2.04L6.49 3.24H4.3l13.31 17.41z" />
            </svg>
        );
    }

    return (
        <svg className="h-4 w-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
        </svg>
    );
}