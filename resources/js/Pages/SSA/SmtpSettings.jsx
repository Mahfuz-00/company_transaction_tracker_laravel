import React, { useState } from 'react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import Card from '@/Components/UI/Card';
import Field from '@/Components/UI/Field';
import { Spinner } from '@/Components/UI/Loading';
import { Head, useForm, usePage } from '@inertiajs/react';

/**
 * Software Super Admin - SMTP / Mail Settings.
 *
 * A dedicated SSA settings sub-module that points the WHOLE platform at an SMTP
 * relay. The stored configuration overrides the env mailer at boot, so no
 * redeploy is needed. The form is PRE-FILLED with the production Brevo relay
 * (server, port, login); the password is never sent to the browser - a stored
 * secret is shown only as a "leave blank to keep" hint.
 *
 * PROPS (from SmtpSettingsController::edit)
 *  - settings: { enabled, host, port, username, password, has_password,
 *                encryption, from_address, from_name }
 *  - effectiveDriver: the mailer currently in effect ('smtp' | 'log' | ...).
 */
export default function SmtpSettings({ settings = {}, effectiveDriver = 'log' }) {
    const { flash } = usePage().props;

    const { data, setData, put, processing, errors, recentlySuccessful } = useForm({
        enabled: Boolean(settings.enabled),
        host: settings.host || '',
        port: settings.port ?? 587,
        username: settings.username || '',
        password: '',
        encryption: settings.encryption || 'tls',
        from_address: settings.from_address || '',
        from_name: settings.from_name || '',
    });

    // A SEPARATE form for the test email, so sending a test never touches the
    // unsaved (or saved) relay form state.
    const test = useForm({ test_email: '' });
    const [showPassword, setShowPassword] = useState(false);

    const submit = (event) => {
        event.preventDefault();
        put(route('ssa.smtp.update'), { preserveScroll: true, onSuccess: () => setData('password', '') });
    };

    const sendTest = (event) => {
        event.preventDefault();
        test.post(route('ssa.smtp.test'), { preserveScroll: true });
    };

    return (
        <SettingsLayout title="SMTP Settings">
            <Head title="SMTP Settings" />

            <div className="space-y-6">
                <div className="flex flex-col gap-3 rounded-2xl bg-gradient-to-br from-slate-900 to-slate-800 p-6 text-white shadow-sm sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p className="text-[11px] font-bold uppercase tracking-wider text-slate-300">
                            Software Super Admin
                        </p>
                        <h1 className="mt-1 text-xl font-bold">SMTP / Mail Settings</h1>
                        <p className="mt-1 text-xs text-slate-300">
                            Configure the outbound mail relay for the whole platform. Changes apply immediately - no redeploy.
                        </p>
                    </div>
                    <span
                        className={`inline-flex items-center gap-2 self-start rounded-full border px-3 py-1 text-xs font-semibold sm:self-auto ${effectiveDriver === 'smtp'
                            ? 'border-emerald-400/40 bg-emerald-400/10 text-emerald-200'
                            : 'border-amber-400/40 bg-amber-400/10 text-amber-200'
                            }`}
                    >
                        <span className={`h-1.5 w-1.5 rounded-full ${effectiveDriver === 'smtp' ? 'bg-emerald-400' : 'bg-amber-400'}`} />
                        Active mailer: {effectiveDriver}
                    </span>
                </div>

                {flash?.success && (
                    <div role="status" className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div role="status" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
                        {flash.error}
                    </div>
                )}

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <form onSubmit={submit} className="lg:col-span-2">
                        <Card className="space-y-6 border border-slate-200/80 bg-white p-6 shadow-xs">
                            <div className="flex items-center justify-between border-b border-slate-100 pb-4">
                                <div>
                                    <h3 className="text-base font-bold text-slate-900">SMTP Relay</h3>
                                    <p className="mt-0.5 text-xs text-slate-500">Credentials for the mail server used to deliver all platform email.</p>
                                </div>
                                <label className="flex cursor-pointer items-center gap-2 text-sm font-semibold text-slate-700">
                                    <input
                                        type="checkbox"
                                        checked={data.enabled}
                                        onChange={(e) => setData('enabled', e.target.checked)}
                                        className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                    />
                                    Enabled
                                </label>
                            </div>

                            {!data.enabled && (
                                <div className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs font-medium text-amber-800">
                                    SMTP is disabled - the platform falls back to the mailer configured in the environment.
                                    Enable it to be prompted for the required connection fields.
                                </div>
                            )}

                            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <div className="sm:col-span-2">
                                    <Field
                                        label="SMTP Host"
                                        name="host"
                                        required={data.enabled}
                                        value={data.host}
                                        error={errors.host}
                                        placeholder="e.g. smtp-relay.brevo.com"
                                        onChange={(e) => setData('host', e.target.value)}
                                    />
                                </div>

                                <Field
                                    label="Port"
                                    name="port"
                                    type="number"
                                    required={data.enabled}
                                    min={1}
                                    max={65535}
                                    value={data.port}
                                    error={errors.port}
                                    onChange={(e) => setData('port', e.target.value)}
                                />

                                <Field
                                    label="Encryption"
                                    name="encryption"
                                    type="select"
                                    required={data.enabled}
                                    value={data.encryption}
                                    error={errors.encryption}
                                    options={[
                                        { value: 'tls', label: 'TLS (STARTTLS, port 587)' },
                                        { value: 'ssl', label: 'SSL (implicit, port 465)' },
                                        { value: 'none', label: 'None' },
                                    ]}
                                    onChange={(e) => setData('encryption', e.target.value)}
                                />

                                <Field
                                    label="Username"
                                    name="username"
                                    required={data.enabled}
                                    value={data.username}
                                    error={errors.username}
                                    placeholder="SMTP username"
                                    onChange={(e) => setData('username', e.target.value)}
                                />

                                <div>
                                    <label htmlFor="password" className="mb-1.5 block text-xs font-semibold text-slate-700">
                                        Password
                                        {!(data.enabled && !settings.has_password) && (
                                            <span className="font-normal text-slate-400"> (optional)</span>
                                        )}
                                    </label>
                                    <div className="flex items-center gap-2">
                                        <input
                                            id="password"
                                            type={showPassword ? 'text' : 'password'}
                                            value={data.password}
                                            autoComplete="new-password"
                                            onChange={(e) => setData('password', e.target.value)}
                                            placeholder={settings.has_password ? '•••••••• (stored)' : 'SMTP password'}
                                            className="w-full rounded-xl border border-slate-200 px-3.5 py-2 text-sm text-slate-900 shadow-2xs transition-all focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10"
                                        />
                                        <button
                                            type="button"
                                            onClick={() => setShowPassword((v) => !v)}
                                            className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50"
                                        >
                                            {showPassword ? 'Hide' : 'Show'}
                                        </button>
                                    </div>
                                    {errors.password && <p className="mt-1.5 text-xs font-medium text-rose-600">{errors.password}</p>}
                                    {settings.has_password && !data.password && (
                                        <p className="mt-1.5 text-[11px] text-slate-400">A password is stored. Leave blank to keep it.</p>
                                    )}
                                </div>

                                <div className="sm:col-span-2">
                                    <Field
                                        label="From Address"
                                        name="from_address"
                                        type="email"
                                        required={data.enabled}
                                        value={data.from_address}
                                        error={errors.from_address}
                                        placeholder="no-reply@yourdomain.com"
                                        onChange={(e) => setData('from_address', e.target.value)}
                                    />
                                </div>

                                <div className="sm:col-span-2">
                                    <Field
                                        label="From Name"
                                        name="from_name"
                                        value={data.from_name}
                                        error={errors.from_name}
                                        placeholder="Platform notifications"
                                        onChange={(e) => setData('from_name', e.target.value)}
                                    />
                                </div>
                            </div>

                            <div className="flex items-center justify-end gap-3 border-t border-slate-100 pt-4">
                                {recentlySuccessful && (
                                    <span className="text-xs font-semibold text-emerald-600">Saved.</span>
                                )}
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white shadow-xs transition-all hover:bg-indigo-700 disabled:opacity-50"
                                >
                                    {processing && <Spinner className="h-4 w-4" />}
                                    {processing ? 'Saving...' : 'Save Settings'}
                                </button>
                            </div>
                        </Card>
                    </form>

                    <div className="space-y-6">
                        <Card className="space-y-4 border border-slate-200/80 bg-white p-6 shadow-xs">
                            <div>
                                <h3 className="text-sm font-bold text-slate-900">Send a test email</h3>
                                <p className="mt-0.5 text-xs text-slate-500">
                                    Verify the SAVED configuration delivers mail. Save first if you just changed it.
                                </p>
                            </div>

                            <form onSubmit={sendTest} className="space-y-3">
                                <Field
                                    label="Recipient"
                                    name="test_email"
                                    type="email"
                                    value={test.data.test_email}
                                    error={test.errors.test_email}
                                    placeholder="you@example.com"
                                    onChange={(e) => test.setData('test_email', e.target.value)}
                                />
                                <button
                                    type="submit"
                                    disabled={test.processing}
                                    className="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50 disabled:opacity-50"
                                >
                                    {test.processing && <Spinner className="h-4 w-4" />}
                                    {test.processing ? 'Sending...' : 'Send test email'}
                                </button>
                            </form>
                        </Card>

                        <Card className="border border-slate-200/80 bg-slate-50 p-6 text-xs text-slate-500 shadow-xs">
                            <p className="font-semibold text-slate-700">How this works</p>
                            <ul className="mt-2 list-disc space-y-1 pl-4">
                                <li>The password is stored encrypted and never shown again.</li>
                                <li>Saving overrides the environment mailer for the running app.</li>
                                <li>Institution-facing email (welcome, invitations, notifications) all use this relay.</li>
                            </ul>
                        </Card>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    );
}
