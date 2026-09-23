import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

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
 * PROPS
 *   - `inviteCode`      : pre-filled code, present when the user arrived via an
 *                         invite link (so the field is already populated).
 *   - `institutionName` : the resolved name of the matched institution, or null
 *                         when the code is unknown — drives the "Matched" hint
 *                         versus the "ask your admin" helper text.
 *   - `roles`           : roles allowed for a new signup; the first is used as the
 *                         default `role` value on the payload.
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

    const [showPassword, setShowPassword] = useState(false);

    const strength = useMemo(() => getPasswordStrength(data.password), [data.password]);

    const passwordMismatch =
        data.password_confirmation.length > 0 && data.password !== data.password_confirmation;

    const submit = (e) => {
        e.preventDefault();

        post(route('register'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <GuestLayout
            heading="Create your account"
            subheading={institutionName
                ? `You're joining ${institutionName}.`
                : 'Enter your institution invite code to join your workspace.'}
        >
            <Head title="Register" />

            <form onSubmit={submit} className="space-y-4">
                {/* Invitation code - the tenant-mapping key */}
                <div>
                    <InputLabel htmlFor="invite_code" value="Institution Invite Code" />

                    <TextInput
                        id="invite_code"
                        name="invite_code"
                        value={data.invite_code}
                        className="mt-1 block w-full uppercase tracking-widest"
                        placeholder="e.g. AB12CD34"
                        onChange={(e) => setData('invite_code', e.target.value.toUpperCase())}
                        required
                    />

                    {institutionName ? (
                        <p className="mt-1.5 text-xs font-medium text-emerald-600">
                            ✓ Matched: {institutionName}
                        </p>
                    ) : (
                        <p className="mt-1.5 text-xs text-slate-500">
                            Ask your institution admin for this code - it maps your account to the
                            right workspace.
                        </p>
                    )}

                    <InputError message={errors.invite_code} className="mt-2" />
                </div>
                {/* Name */}
                <div>
                    <InputLabel htmlFor="name" value="Full Name" />

                    <TextInput
                        id="name"
                        name="name"
                        value={data.name}
                        className="mt-1 block w-full"
                        autoComplete="name"
                        isFocused={true}
                        placeholder="e.g. Farhan Hossain"
                        onChange={(e) => setData('name', e.target.value)}
                        required
                    />

                    <InputError message={errors.name} className="mt-2" />
                </div>

                {/* Email */}
                <div>
                    <InputLabel htmlFor="email" value="Email Address" />

                    <TextInput
                        id="email"
                        type="email"
                        name="email"
                        value={data.email}
                        className="mt-1 block w-full"
                        autoComplete="username"
                        placeholder="name@example.com"
                        onChange={(e) => setData('email', e.target.value)}
                        required
                    />

                    <InputError message={errors.email} className="mt-2" />
                </div>

                {/* Phone (optional) */}
                <div>
                    <InputLabel htmlFor="phone" value="Phone (optional)" />

                    <TextInput
                        id="phone"
                        type="tel"
                        name="phone"
                        value={data.phone}
                        className="mt-1 block w-full"
                        autoComplete="tel"
                        placeholder="+8801XXXXXXXXX"
                        onChange={(e) => setData('phone', e.target.value)}
                    />

                    <InputError message={errors.phone} className="mt-2" />
                </div>

                {/* Password */}
                <div>
                    <InputLabel htmlFor="password" value="Password" />

                    <div className="relative mt-1">
                        <TextInput
                            id="password"
                            type={showPassword ? 'text' : 'password'}
                            name="password"
                            value={data.password}
                            className="block w-full pr-12"
                            autoComplete="new-password"
                            placeholder="At least 8 characters"
                            onChange={(e) => setData('password', e.target.value)}
                            required
                        />

                        <button
                            type="button"
                            onClick={() => setShowPassword((visible) => !visible)}
                            aria-label={showPassword ? 'Hide password' : 'Show password'}
                            className="absolute inset-y-0 right-0 flex items-center px-3 text-xs font-semibold text-slate-500 transition-colors hover:text-slate-800"
                        >
                            {showPassword ? 'Hide' : 'Show'}
                        </button>
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

                    <InputError message={errors.password} className="mt-2" />
                </div>

                {/* Confirm password */}
                <div>
                    <InputLabel
                        htmlFor="password_confirmation"
                        value="Confirm Password"
                    />

                    <TextInput
                        id="password_confirmation"
                        type={showPassword ? 'text' : 'password'}
                        name="password_confirmation"
                        value={data.password_confirmation}
                        className="mt-1 block w-full"
                        autoComplete="new-password"
                        placeholder="Repeat your password"
                        onChange={(e) =>
                            setData('password_confirmation', e.target.value)
                        }
                        required
                    />

                    {passwordMismatch && (
                        <p role="alert" className="mt-2 text-sm text-pink-300">
                            Passwords do not match.
                        </p>
                    )}

                    <InputError
                        message={errors.password_confirmation}
                        className="mt-2"
                    />
                </div>

                {/* Submit */}
                <div className="pt-2">
                    <PrimaryButton
                        className="w-full justify-center"
                        disabled={processing || passwordMismatch}
                    >
                        {processing ? 'Creating account...' : 'Create Account'}
                    </PrimaryButton>
                </div>

                <p className="text-center text-xs leading-relaxed text-slate-500">
                    New accounts join as a <strong>Member</strong> (or Meal Manager if your institution
                    allows it). An admin can grant additional access later.
                </p>

                <p className="text-center text-sm text-slate-600">
                    Already registered?{' '}
                    <Link
                        href={route('login')}
                        className="font-semibold text-indigo-600 underline-offset-2 transition-colors hover:text-indigo-800 hover:underline focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                    >
                        Log in instead
                    </Link>
                </p>
            </form>
        </GuestLayout>
    );
}
