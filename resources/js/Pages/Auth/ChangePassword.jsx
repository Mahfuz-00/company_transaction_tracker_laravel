import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Field from '@/Components/UI/Field';
import { Head, useForm } from '@inertiajs/react';

/**
 * Change-password screen.
 *
 * Doubles as the forced first-login screen: when a user was given a temporary
 * ("demo") password, the EnsurePasswordIsChanged middleware drops them here and
 * blocks everything else until a new password is set.
 */
export default function ChangePassword({ mustChange = false }) {
    const { data, setData, put, processing, errors, reset } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('password.change.update'), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <h2 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">
                        {mustChange ? 'Set your password' : 'Change password'}
                    </h2>
                    <p className="mt-0.5 text-xs font-medium text-slate-500">
                        {mustChange
                            ? 'You signed in with a temporary password. Choose a new one to continue.'
                            : 'Update the password for your account.'}
                    </p>
                </div>
            }
        >
            <Head title="Change Password" />

            <div className="mx-auto max-w-lg">
                {mustChange && (
                    <div className="mb-4 flex items-start gap-2 rounded-xl border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                        <svg className="mt-0.5 h-4 w-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                        </svg>
                        <span>
                            For security, you must replace the temporary password before using the platform.
                        </span>
                    </div>
                )}

                <form onSubmit={submit} className="space-y-4 rounded-2xl border-slate-200 bg-white p-6 shadow-sm">
                    <Field
                        label="Current password"
                        name="current_password"
                        type="password"
                        required
                        value={data.current_password}
                        error={errors.current_password}
                        placeholder="Your temporary or existing password"
                        onChange={(e) => setData('current_password', e.target.value)}
                    />

                    <Field
                        label="New password"
                        name="password"
                        type="password"
                        required
                        value={data.password}
                        error={errors.password}
                        placeholder="At least 8 characters"
                        hint="Choose something you'll remember and no one else can guess."
                        onChange={(e) => setData('password', e.target.value)}
                    />

                    <Field
                        label="Confirm new password"
                        name="password_confirmation"
                        type="password"
                        required
                        value={data.password_confirmation}
                        error={errors.password_confirmation}
                        placeholder="Repeat the new password"
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                    />

                    <button
                        type="submit"
                        disabled={processing}
                        className="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-[var(--accent)] px-6 py-2.5 text-sm font-bold text-white shadow-sm transition-opacity hover:opacity-90 disabled:opacity-50"
                    >
                        {processing ? 'Saving...' : 'Update password'}
                    </button>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
