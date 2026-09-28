import React, { forwardRef, useState } from 'react';

/**
 * PasswordInput — a password field with an interactive visibility toggle.
 *
 * WHY THIS EXISTS
 * ---------------
 * Every password field in the app (login, register, password setup / invite
 * accept, password change, admin user create, profile security) previously
 * rendered a bare `<input type="password">` with no way to reveal what was typed.
 * On mobile that makes typos almost certain, and on the invite-accept screen the
 * user is CHOOSING a new password, so being able to see it is a real usability
 * need, not a nicety.
 *
 * This component is the single implementation of that affordance, so the eye
 * toggle behaves identically (and is testable identically) everywhere.
 *
 * ACCESSIBILITY
 * -------------
 *   - The toggle is a real <button> (keyboard reachable, Enter/Space activate).
 *   - `aria-label` flips between "Show password"/"Hide password" so assistive
 *     tech announces the action, and `aria-pressed` reflects the state.
 *   - The input keeps a stable `id` so the existing <InputLabel htmlFor="...">
 *     association still works.
 *
 * TEST HOOKS
 * ----------
 *   - `data-testid="password-toggle"` on the button (the Dusk suite clicks it).
 *   - The input type flips between 'password' and 'text', which is what the
 *     browser test asserts on.
 *
 * Props: everything a normal <input> takes, plus:
 *   - `containerClassName` : extra classes for the wrapper (rarely needed).
 */
const PasswordInput = forwardRef(function PasswordInput(
    { className = '', containerClassName = '', id, ...props },
    ref,
) {
    const [visible, setVisible] = useState(false);

    return (
        <div className={`relative ${containerClassName}`}>
            <input
                {...props}
                id={id}
                ref={ref}
                // The whole point of the toggle: swap the input type.
                type={visible ? 'text' : 'password'}
                // Leave room on the right for the toggle so long values never sit
                // underneath it.
                className={`${className} pr-12`}
            />

            <button
                type="button"
                onClick={() => setVisible((v) => !v)}
                data-testid="password-toggle"
                aria-label={visible ? 'Hide password' : 'Show password'}
                aria-pressed={visible}
                // Keep the control out of the tab-order before the submit button
                // but still reachable; it sits visually inside the field.
                className="absolute inset-y-0 right-0 flex items-center px-3 text-slate-400 transition-colors hover:text-slate-700 focus:outline-none focus-visible:text-indigo-600"
            >
                {visible ? (
                    /* Eye-off: the password is currently VISIBLE. */
                    <svg
                        className="h-4.5 w-4.5"
                        style={{ height: '1.125rem', width: '1.125rem' }}
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="1.8"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"
                        />
                    </svg>
                ) : (
                    /* Eye: the password is currently HIDDEN. */
                    <svg
                        className="h-4.5 w-4.5"
                        style={{ height: '1.125rem', width: '1.125rem' }}
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="1.8"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"
                        />
                        <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                )}
            </button>
        </div>
    );
});

export default PasswordInput;