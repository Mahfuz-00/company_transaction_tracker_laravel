import React, { useState } from 'react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { Head, Link, useForm } from '@inertiajs/react';

/**
 * Software Super Admin - Platform Announcements & System Broadcasts.
 *
 * Compose one message for the whole platform (maintenance windows, pricing
 * changes, outages) and target an audience: all staff, all members, admins
 * only, or everyone. A history of what was sent is kept below.
 */
export default function Broadcasts({ history = [], audiences = [], severities = [], stats = {} }) {
    const [preview, setPreview] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        title: '',
        body: '',
        audience: 'institution_admins',
        severity: 'info',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('ssa.broadcasts.store'), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    const severityTone = {
        info: { chip: 'bg-sky-50 text-sky-700 border-sky-100', dot: 'bg-sky-500' },
        success: { chip: 'bg-emerald-50 text-emerald-700 border-emerald-100', dot: 'bg-emerald-500' },
        warning: { chip: 'bg-amber-50 text-amber-700 border-amber-100', dot: 'bg-amber-500' },
        critical: { chip: 'bg-rose-50 text-rose-700 border-rose-100', dot: 'bg-rose-500' },
    };

    return (
        <SettingsLayout title="Broadcasts">
            <Head title="Platform Broadcasts" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-col gap-3 rounded-2xl bg-gradient-to-br from-slate-900 to-slate-800 p-6 text-white shadow-sm sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p className="text-[11px] font-bold uppercase tracking-wider text-slate-300">
                            Software Super Admin
                        </p>
                        <h1 className="mt-1 text-xl font-bold">Platform Announcements &amp; Broadcasts</h1>
                        <p className="mt-1 text-xs text-slate-300">
                            Send a system-wide notice to every institution at once.
                        </p>
                    </div>
                    <Link
                        href={route('ssa.dashboard')}
                        className="inline-flex flex-shrink-0 items-center justify-center rounded-xl bg-white/10 px-4 py-2 text-xs font-semibold text-white ring-1 ring-white/20 transition-colors hover:bg-white/20"
                    >
                        Back to Dashboard
                    </Link>
                </div>

                {/* Stats */}
                <div className="grid grid-cols-3 gap-4">
                    <StatCard label="Total Broadcasts" value={stats.total_broadcasts ?? 0} />
                    <StatCard label="Total Recipients Reached" value={stats.total_recipients ?? 0} />
                    <StatCard label="Last Sent" value={stats.last_sent || 'Never'} small />
                </div>

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-12 lg:items-start">
                    {/* Composer */}
                    <form onSubmit={submit} className="space-y-5 rounded-2xl border-slate-200/80 bg-white p-6 shadow-xs lg:col-span-7">
                        <div className="border-b border-slate-100 pb-4">
                            <h3 className="text-base font-bold text-slate-900">Compose Broadcast</h3>
                            <p className="mt-0.5 text-xs text-slate-500">
                                Delivered instantly to each recipient's in-app notifications.
                            </p>
                        </div>

                        <Field label="Audience" error={errors.audience}>
                            <div className="grid grid-cols-2 gap-2">
                                {audiences.map((a) => (
                                    <button
                                        key={a.value}
                                        type="button"
                                        onClick={() => setData('audience', a.value)}
                                        className={`rounded-xl border px-3 py-2 text-left text-xs font-semibold transition-all ${data.audience === a.value
                                                ? 'border-indigo-300 bg-indigo-50 text-indigo-700'
                                                : 'border-slate-200 text-slate-600 hover:bg-slate-50'
                                            }`}
                                    >
                                        {a.label}
                                    </button>
                                ))}
                            </div>
                        </Field>

                        <Field label="Severity" error={errors.severity}>
                            <div className="flex flex-wrap gap-2">
                                {severities.map((s) => (
                                    <button
                                        key={s.value}
                                        type="button"
                                        onClick={() => setData('severity', s.value)}
                                        className={`inline-flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-xs font-semibold transition-all ${data.severity === s.value
                                                ? severityTone[s.value].chip
                                                : 'border-slate-200 text-slate-600 hover:bg-slate-50'
                                            }`}
                                    >
                                        <span className={`h-2 w-2 rounded-full ${severityTone[s.value].dot}`} />
                                        {s.label}
                                    </button>
                                ))}
                            </div>
                        </Field>

                        <Field label="Title" error={errors.title}>
                            <input
                                type="text"
                                value={data.title}
                                onChange={(e) => setData('title', e.target.value)}
                                placeholder="e.g. Scheduled maintenance this Saturday"
                                className="w-full rounded-lg border-slate-300 px-3.5 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                            />
                        </Field>

                        <Field label="Message" error={errors.body}>
                            <textarea
                                rows={5}
                                value={data.body}
                                onChange={(e) => setData('body', e.target.value)}
                                placeholder="Explain what is changing, when, and any action recipients should take."
                                className="w-full rounded-lg border-slate-300 px-3.5 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                            />
                        </Field>

                        <div className="flex items-center gap-2">
                            <button
                                type="submit"
                                disabled={processing}
                                className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 disabled:opacity-50"
                            >
                                {processing ? 'Sending...' : 'Send broadcast'}
                            </button>
                            <button
                                type="button"
                                onClick={() => setPreview((p) => !p)}
                                className="rounded-lg border-slate-300 px-4 py-2.5 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50"
                            >
                                {preview ? 'Hide' : 'Show'} preview
                            </button>
                        </div>
                    </form>

                    {/* Preview */}
                    <div className="lg:col-span-5 lg:sticky lg:top-6">
                        <div className="rounded-2xl border-slate-200/80 bg-white p-5 shadow-xs">
                            <h4 className="mb-4 text-xs font-bold uppercase tracking-wider text-slate-400">
                                Recipient Preview
                            </h4>
                            {preview ? (
                                <div className="rounded-xl border-slate-200 p-4">
                                    <div className="flex items-start gap-3">
                                        <span className={`mt-1 h-2.5 w-2.5 flex-shrink-0 rounded-full ${severityTone[data.severity].dot}`} />
                                        <div className="min-w-0">
                                            <p className="text-sm font-bold text-slate-900">
                                                {data.title || 'Your title appears here'}
                                            </p>
                                            <p className="mt-1 whitespace-pre-wrap text-xs leading-relaxed text-slate-600">
                                                {data.body || 'Your message body appears here.'}
                                            </p>
                                            <p className="mt-2 text-[10px] uppercase tracking-wider text-slate-400">
                                                Platform broadcast · just now
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            ) : (
                                <p className="text-xs text-slate-400">
                                    Toggle "Show preview" to see how the notification renders in the
                                    recipient's bell.
                                </p>
                            )}
                        </div>
                    </div>
                </div>

                {/* History */}
                <section className="rounded-2xl border-slate-200/80 bg-white shadow-xs">
                    <div className="border-b border-slate-100 px-6 py-4">
                        <h3 className="text-base font-bold text-slate-900">Broadcast History</h3>
                        <p className="mt-0.5 text-xs text-slate-500">Everything the platform has announced.</p>
                    </div>
                    <div className="divide-y divide-slate-100">
                        {history.map((b) => (
                            <div key={b.id} className="flex items-start gap-4 px-6 py-4">
                                <span className={`mt-1.5 h-2.5 w-2.5 flex-shrink-0 rounded-full ${severityTone[b.severity]?.dot || 'bg-slate-400'}`} />
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <p className="text-sm font-bold text-slate-800">{b.title}</p>
                                        <span className={`rounded-full border px-2 py-0.5 text-[10px] font-bold ${severityTone[b.severity]?.chip || 'border-slate-200 bg-slate-100 text-slate-600'}`}>
                                            {b.audience_label}
                                        </span>
                                    </div>
                                    <p className="mt-1 line-clamp-2 text-xs text-slate-500">{b.body}</p>
                                    <p className="mt-1 text-[11px] text-slate-400">
                                        {b.recipients} recipient{b.recipients === 1 ? '' : 's'} · {b.sent_by || 'System'} · {b.sent_human}
                                    </p>
                                </div>
                            </div>
                        ))}
                        {history.length === 0 && (
                            <div className="px-6 py-10 text-center text-sm text-slate-400">
                                No broadcasts sent yet.
                            </div>
                        )}
                    </div>
                </section>
            </div>
        </SettingsLayout>
    );
}

function StatCard({ label, value, small = false }) {
    return (
        <div className="rounded-2xl border-slate-200/80 bg-white p-5 shadow-xs">
            <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">{label}</p>
            <p className={`mt-1.5 font-bold text-slate-900 ${small ? 'text-sm' : 'text-2xl'}`}>{value}</p>
        </div>
    );
}

function Field({ label, error, children }) {
    return (
        <div>
            <label className="mb-1.5 block text-xs font-semibold text-slate-700">{label}</label>
            {children}
            {error && <p className="mt-1 text-xs text-rose-500">{error}</p>}
        </div>
    );
}
