import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PasswordInput from '@/Components/PasswordInput';
import PrimaryButton from '@/Components/PrimaryButton';
import SsoButtons from '@/Components/SsoButtons';
import TextInput from '@/Components/TextInput';
import AuthSplitLayout from '@/Layouts/AuthSplitLayout';
import useInviteCodeValidation from '@/Utils/useInviteCodeValidation';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useMemo } from 'react';
/* Strength meter — purely presentational guidance, real rules are enforced server-side. */
function getPasswordStrength(password) {
    if (!password) return { score: 0, label: '', tone: '' };

    let score = 0;
    if (password.length >= 8) score += 1;
    if (password.length >= 12) score += 1;
    if (/[A-Z]/.test(password) && /[a-z]/.test(password)) score += 1;
    if (/\d/.test(password)) score += 1;
    if (/[^A-Za-z0-9]/.test(password)) score += 1;

    if (score <= 2) return { score, label: 'Weak', tone: 'bg-rose-500 text-rose-600' };
    if (score === 3) return { score, label: 'Fair', tone: 'bg-amber-500 text-amber-600' };
    if (score === 4) return { score, label: 'Good', tone: 'bg-sky-500 text-sky-600' };
    return { score, label: 'Strong', tone: 'bg-emerald-500 text-emerald-600' };
}

/**
 * Self-service registration screen.
 *
 * Inertia page component whose whole job is to create a NEW account inside an
 * existing workspace, so it is built around the institution INVITE CODE — the
 * tenant-mapping key that ties the new user to the right institution.
 *
 * THE INVITE-CODE GATE (the point of this screen)
 *   SSO/OAuth signup is allowed ONLY once the typed code has been CONFIRMED by
 *   the server (`useInviteCodeValidation` -> POST /register/validate-invite-code).
 *   A client-side "not empty" check is not a gate: it would unlock the provider
 *   buttons for a code that resolves to nothing, and the user would complete a
 *   whole OAuth round-trip before being rejected on the way back.
 *
 * PROPS
 *   - `inviteCode`      : pre-filled code, present when the user arrived via an
 *                         invite link (so the field is already populated).
 *   - `institutionName` : the server-resolved name of the matched institution, or
 *                         null when arriving without a valid pre-filled code.
 *   - `roles`           : roles allowed for a new signup; the first is used as the
 *                         default `role` value on the payload.
 *
 * LAYOUT
 *   Renders inside AuthSplitLayout: light introduction panel on the left
 *   (desktop only), the sign-up form on the right with the SSO buttons strictly
 *   BELOW it (the layout's `sso` slot).
 *
 * Inertia / React concepts on show:
 *   - `useForm` holds every field, seeded from the page props.
 *   - `post(route('register'))` submits via Inertia; `onFinish` blanks the two
 *     password fields after the round-trip.
 *   - `errors.<field>` are server-side validation messages rendered inline.
 *   - The strength meter and the mismatch check are LOCAL (client-side) feedback
 *     only — the authoritative rules still run on the server.
 */
export default function Register({ inviteCode = '', institutionName = null, roles = [] }) {
    // SSO providers the server has configured (empty when none are set up).
    const { oauth = [] } = usePage().props;

    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        phone: '',
        password: '',
        password_confirmation: '',
        // Tenant-mapping key: an account is always created inside an institution.
        invite_code: inviteCode,
        role: roles[0] || 'Member',
    });

    /*
     * THE GATE. `status` drives the SSO lock; `isValid` is the unlock condition.
     * When the page arrived with a code already validated server-side
     * (`institutionName` is present), the hook re-confirms it on mount and the
     * buttons unlock as soon as that round-trip returns.
     */
    const invite = useInviteCodeValidation(data.invite_code);

    const strength = useMemo(() => getPasswordStrength(data.password), [data.password]);
    const passwordMismatch =
        data.password_confirmation.length > 0 && data.password !== data.password_confirmation;

    const submit = (e) => {
        e.preventDefault();

        post(route('register'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    // One shared input treatment, so every field on the screen matches.
    const inputClass =
        'mt-1 block w-full rounded-xl border-slate-200 bg-white px-4 py-3 text-sm text-slate-800 shadow-sm transition-all focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 placeholder:text-slate-400';

    return (
        <AuthSplitLayout
            heading="Create your account"
            subheading={
                invite.institutionName || institutionName
                    ? `You're joining ${invite.institutionName || institutionName}.`
                    : 'Enter your institution invite code to join your workspace.'
            }
            /*
             * SSO SIGN-UP — BELOW THE FORM.
             *
             * `requireInviteCode` plus the hook's `isValid` means the provider
             * buttons stay LOCKED until the server has confirmed the code. The
             * code rides along in the redirect, so the OAuth round-trip resolves
             * to the same workspace the form would have used.
             */
            sso={
                <SsoButtons
                    providers={oauth}
                    requireInviteCode
                    inviteCode={data.invite_code}
                    inviteCodeValidated={invite.isValid}
                    subtitle="Or sign up with"
                    note="Your invite code above is used to place you in the right workspace."
                />
            }
            footer={
                <p className="text-center text-sm text-slate-500">
                    Already registered?{' '}
                    <Link
                        href={route('login')}
                        className="font-semibold text-indigo-600 transition-colors hover:text-indigo-700"
                    >
                        Log in instead
                    </Link>
                </p>
            }
        >
            <Head title="Register" />

            <form onSubmit={submit} className="space-y-4">
                {/* Invitation code - the tenant-mapping key */}
                <div>
                    <InputLabel
                        htmlFor="invite_code"
                        value="Institution Invite Code"
                        className="mb-1.5 text-[11px] font-bold uppercase tracking-wider text-slate-400"
                    />

                    <TextInput
                        id="invite_code"
                        name="invite_code"
                        value={data.invite_code}
                        className={`${inputClass} uppercase tracking-widest`}
                        placeholder="e.g. AB12CD34"
                        onChange={(e) => setData('invite_code', e.target.value.toUpperCase())}
                        required
                    />

                    {/* Live validation feedback. The states are exclusive, so the
                        user sees exactly one message: checking, matched, invalid,
                        or the neutral "ask your admin" hint. */}
                    {invite.status === 'checking' && (
                        <p className="mt-1.5 flex items-center gap-1.5 text-xs font-medium text-slate-500">
                            <svg className="h-3 w-3 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                            </svg>
                            Checking code…
                        </p>
                    )}

                    {invite.status === 'valid' && (
                        <p
                            data-testid="invite-code-valid"
                            className="mt-1.5 text-xs font-medium text-emerald-600"
                        >
                            ✓ Matched: {invite.institutionName}
                        </p>
                    )}

                    {invite.status === 'invalid' && (
                        <p role="alert" data-testid="invite-code-invalid" className="mt-1.5 text-xs font-medium text-rose-600">
                            {invite.message}
                        </p>
                    )}

                    {invite.status === 'idle' && (
                        <p className="mt-1.5 text-xs text-slate-500">
                            Ask your institution admin for this code - it maps your account to the
                            right workspace.
                        </p>
                    )}

                    <InputError message={errors.invite_code} className="mt-2" />
                </div>
                {/* Name */}
                <div>
                    <InputLabel
                        htmlFor="name"
                        value="Full Name"
                        className="mb-1.5 text-[11px] font-bold uppercase tracking-wider text-slate-400"
                    />

                    <TextInput
                        id="name"
                        name="name"
                        value={data.name}
                        className={inputClass}
                        autoComplete="name"
                        isFocused={true}
                        placeholder="e.g. Farhan Hossain"
                        onChange={(e) => setData('name', e.target.value)}
                        required
                    />

                    <InputError message={errors.name} className="mt-1.5 text-xs font-medium text-rose-600" />
                </div>

                {/* Email */}
                <div>
                    <InputLabel
                        htmlFor="email"
                        value="Email Address"
                        className="mb-1.5 text-[11px] font-bold uppercase tracking-wider text-slate-400"
                    />

                    <TextInput
                        id="email"
                        type="email"
                        name="email"
                        value={data.email}
                        className={inputClass}
                        autoComplete="username"
                        placeholder="name@example.com"
                        onChange={(e) => setData('email', e.target.value)}
                        required
                    />

                    <InputError message={errors.email} className="mt-1.5 text-xs font-medium text-rose-600" />
                </div>

                {/* Phone (optional) */}
                <div>
                    <InputLabel
                        htmlFor="phone"
                        value="Phone (optional)"
                        className="mb-1.5 text-[11px] font-bold uppercase tracking-wider text-slate-400"
                    />

                    <TextInput
                        id="phone"
                        type="tel"
                        name="phone"
                        value={data.phone}
                        className={inputClass}
                        autoComplete="tel"
                        placeholder="+8801XXXXXXXXX"
                        onChange={(e) => setData('phone', e.target.value)}
                    />

                    <InputError message={errors.phone} className="mt-1.5 text-xs font-medium text-rose-600" />
                </div>

                {/* Password */}
                <div>
                    <InputLabel
                        htmlFor="password"
                        value="Password"
                        className="mb-1.5 text-[11px] font-bold uppercase tracking-wider text-slate-400"
                    />

                    <div className="relative mt-1">
                        {/* Shared PasswordInput: the eye toggle is consistent with
                            every other password box in the app. */}
                        <PasswordInput
                            id="password"
                            name="password"
                            value={data.password}
                            className={inputClass}
                            autoComplete="new-password"
                            placeholder="At least 8 characters"
                            onChange={(e) => setData('password', e.target.value)}
                            required
                        />
                    </div>

                    {/* Strength meter */}
                    {data.password.length > 0 && (
                        <div className="mt-2">
                            <div className="flex gap-1" aria-hidden="true">
                                {[0, 1, 2, 3, 4].map((index) => (
                                    <span
                                        key={index}
                                        className={`h-1 flex-1 rounded-full transition-colors ${
                                            index < strength.score
                                                ? strength.tone.split(' ')[0]
                                                : 'bg-slate-200'
                                        }`}
                                    />
                                ))}
                            </div>
                            <p
                                className={`mt-1 text-xs font-semibold ${
                                    strength.tone.split(' ')[1] || 'text-slate-500'
                                }`}
                            >
                                Password strength: {strength.label}
                            </p>
                        </div>
                    )}

                    <InputError message={errors.password} className="mt-1.5 text-xs font-medium text-rose-600" />
                </div>

                {/* Confirm password */}
                <div>
                    <InputLabel
                        htmlFor="password_confirmation"
                        value="Confirm Password"
                        className="mb-1.5 text-[11px] font-bold uppercase tracking-wider text-slate-400"
                    />

                    <PasswordInput
                        id="password_confirmation"
                        name="password_confirmation"
                        value={data.password_confirmation}
                        className={inputClass}
                        autoComplete="new-password"
                        placeholder="Repeat your password"
                        onChange={(e) =>
                            setData('password_confirmation', e.target.value)
                        }
                        required
                    />

                    {passwordMismatch && (
                        <p role="alert" className="mt-1.5 text-xs font-medium text-rose-600">
                            Passwords do not match.
                        </p>
                    )}

                    <InputError
                        message={errors.password_confirmation}
                        className="mt-1.5 text-xs font-medium text-rose-600"
                    />
                </div>

                {/* Submit */}
                <div className="pt-3">
                    <PrimaryButton
                        className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-7 py-3.5 text-sm font-bold text-white shadow-lg shadow-indigo-600/25 transition-all hover:bg-indigo-700 hover:shadow-xl hover:shadow-indigo-600/30 active:scale-[.98]"
                        disabled={processing || passwordMismatch || !invite.isValid}
                    >
                        {processing ? 'Creating account...' : 'Create Account'}
                    </PrimaryButton>
                </div>

                <p className="text-center text-xs leading-relaxed text-slate-400">
                    New accounts join as a <strong className="text-slate-600">Member</strong> (or Meal
                    Manager if your institution allows it). An admin can grant additional access later.
                </p>
            </form>
        </AuthSplitLayout>
    );
}
