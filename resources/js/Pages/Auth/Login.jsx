import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import useTerminology from '@/Utils/useTerminology';
import { Head, Link, useForm } from '@inertiajs/react';

/**
 * Login screen — the entry point for every signed-in session.
 *
 * Inertia page component. The user enters email + password and POSTs to
 * `route('login')`; Laravel authenticates and redirects to the intended page
 * (or the dashboard), and Inertia swaps the view without a reload.
 *
 * PROPS (the standard Laravel Breeze contract)
 *   - `status`           : a flash message (e.g. after a password reset), shown
 *                          in the green banner.
 *   - `canResetPassword` : when true, the "Forgot password?" link is offered;
 *                          driven by Laravel's password-reset feature flag.
 *
 * Inertia / React concepts on show:
 *   - `useForm` is the form's isolated state (data / errors / processing).
 *   - `post(route('login'))` is an Inertia visit; `onFinish` clears the password
 *     once the request settles, success or failure.
 *   - `useTerminology` supplies the tenant-aware word for "institution" in the
 *     subheading, so the copy follows the workspace type.
 */
export default function Login({ status, canResetPassword }) {
    const { t } = useTerminology();

    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (e) => {
        e.preventDefault();

        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout
            heading="Welcome back"
            subheading={`Sign in to manage your ${t('institution', 'institution').toLowerCase()} meals, deposits, and expenses.`}
        >
            <Head title="Log in" />

            {/* Secure Access Floating Badge */}
            <div className="mb-6 text-center">
                <span className="inline-flex items-center gap-2 rounded-full border border-indigo-100 bg-indigo-50/80 px-3.5 py-1.5 text-xs font-semibold text-indigo-700 shadow-sm shadow-indigo-500/5">
                    <span className="relative flex h-2 w-2">
                        <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-indigo-400 opacity-60" />
                        <span className="relative inline-flex h-2 w-2 rounded-full bg-indigo-500" />
                    </span>
                    Secure Access
                </span>
            </div>

            {status && (
                <div className="mb-6 flex items-center gap-2 rounded-2xl border border-emerald-200 bg-emerald-50/80 p-4 text-sm font-semibold text-emerald-700">
                    <svg className="h-5 w-5 shrink-0 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                    </svg>
                    <span>{status}</span>
                </div>
            )}

            <form onSubmit={submit} className="space-y-5">
                <div>
                    <InputLabel htmlFor="email" value="Email Address" className="mb-1.5 text-[11px] font-bold uppercase tracking-wider text-slate-400" />

                    <TextInput
                        id="email"
                        type="email"
                        name="email"
                        value={data.email}
                        className="mt-1 block w-full rounded-xl border-slate-200 bg-white/60 px-4 py-3 text-sm text-slate-800 shadow-sm transition-all focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/20 placeholder:text-slate-400"
                        autoComplete="username"
                        isFocused={true}
                        placeholder="you@institution.com"
                        onChange={(e) => setData('email', e.target.value)}
                    />

                    <InputError message={errors.email} className="mt-1.5 text-xs font-medium text-rose-600" />
                </div>

                <div>
                    <div className="mb-1.5 flex items-center justify-between">
                        <InputLabel htmlFor="password" value="Password" className="text-[11px] font-bold uppercase tracking-wider text-slate-400" />
                        {canResetPassword && (
                            <Link
                                href={route('password.request')}
                                className="text-xs font-semibold text-indigo-600 transition-colors hover:text-indigo-700"
                            >
                                Forgot password?
                            </Link>
                        )}
                    </div>

                    <TextInput
                        id="password"
                        type="password"
                        name="password"
                        value={data.password}
                        className="mt-1 block w-full rounded-xl border-slate-200 bg-white/60 px-4 py-3 text-sm text-slate-800 shadow-sm transition-all focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/20 placeholder:text-slate-400"
                        autoComplete="current-password"
                        placeholder="••••••••"
                        onChange={(e) => setData('password', e.target.value)}
                    />

                    <InputError message={errors.password} className="mt-1.5 text-xs font-medium text-rose-600" />
                </div>

                <div className="flex items-center justify-between pt-1">
                    <label className="flex cursor-pointer select-none items-center gap-2.5">
                        <Checkbox
                            name="remember"
                            checked={data.remember}
                            className="h-4 w-4 rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                            onChange={(e) =>
                                setData('remember', e.target.checked)
                            }
                        />
                        <span className="text-xs font-semibold text-slate-600">
                            Remember me on this device
                        </span>
                    </label>
                </div>

                <div className="pt-3">
                    <PrimaryButton
                        className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-7 py-3.5 text-sm font-bold text-white shadow-lg shadow-indigo-600/25 transition-all hover:bg-indigo-700 hover:shadow-xl hover:shadow-indigo-600/30 active:scale-[.98]"
                        disabled={processing}
                    >
                        {processing ? (
                            <span className="flex items-center gap-2">
                                <svg className="-ml-1 mr-2 h-4 w-4 animate-spin text-white" fill="none" viewBox="0 0 24 24">
                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Signing in...
                            </span>
                        ) : (
                            'Sign In to Dashboard'
                        )}
                    </PrimaryButton>
                </div>
            </form>
        </GuestLayout>
    );
}