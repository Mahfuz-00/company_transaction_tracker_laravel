import React, { useState } from 'react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { Head, Link, useForm } from '@inertiajs/react';

/**
 * Institution Admin — Workspace Broadcasts.
 *
 * The tenant-level counterpart to the SSA's platform broadcast: compose one
 * message for YOUR institution (a menu change, a holiday notice, a payment
 * reminder) and target an audience inside it — staff only, or all members.
 *
 * INERTIA PROPS
 *   institution {id, name}      - the workspace these broadcasts belong to
 *   history     []              - this institution's sent broadcasts (newest first)
 *   audiences   [{value,label}] - audience options FROM THE SERVER
 *                                 (InstitutionBroadcast::AUDIENCES — no platform-wide option)
 *   severities  [{value,label}] - severity options FROM THE SERVER
 *   stats       {}              - totals for this institution only
 *
 * Deliberately mirrors Pages/SSA/Broadcasts.jsx so the two modules feel like one
 * feature — but every surface here reads the theme tokens, and the selected
 * audience chip uses `var(--accent)`, so the page follows dark/light and the
 * workspace accent automatically.
 */
export default function Broadcasts({
    institution = null,
    history = [],
    audiences = [],
    severities = [],
    stats = {},
}) {
    const [preview, setPreview] = useState(false);

    // `useForm` gives us data/setData/post/errors in one hook (Inertia).
    const { data, setData, post, processing, errors, reset } = useForm({
        title: '',
        body: '',
        audience: 'members',
        severity: 'info',
    });

    const submit = (e) => {
        e.preventDefault();
        // Posts to the institution-scoped route; the server pins the target
        // workspace from the signed-in admin, never from this payload.
        post(route('broadcasts.store'), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    // Semantic severity tones (kept as-is; the app.css dark layer remaps them).
    const severityTone = {
        info: { chip: 'bg-sky-50 text-sky-700 border-sky-100', dot: 'bg-sky-500' },
        success: { chip: 'bg-emerald-50 text-emerald-700 border-emerald-100', dot: 'bg-emerald-500' },
        warning: { chip: 'bg-amber-50 text-amber-700 border-amber-100', dot: 'bg-amber-500' },
        critical: { chip: 'bg-rose-50 text-rose-700 border-rose-100', dot: 'bg-rose-500' },
    };

    return (
        <SettingsLayout title="Broadcasts">
            <Head title="Workspace Broadcasts" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-col gap-3 rounded-2xl bg-gradient-to-br from-slate-900 to-slate-800 p-6 text-white shadow-sm sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p className="text-[11px] font-bold uppercase tracking-wider text-slate-300">
                            Institution Admin
                        </p>
                        <h1 className="mt-1 text-xl font-bold">
                            Workspace Broadcasts{institution?.name ? ` — ${institution.name}` : ''}
                        </h1>
                        <p className="mt-1 text-xs text-slate-300">
                            Send a notice to people inside <strong>your own institution only</strong>.
                        </p>
                    </div>
                    <Link
                        href={route('dashboard')}
                        className="inline-flex flex-shrink-0 items-center justify-center rounded-xl bg-white/10 px-4 py-2 text-xs font-semibold text-white ring-1 ring-white/20 transition-colors hover:bg-white/20"
                    >
                        Back to Dashboard
                    </Link>
                </div>

                {/* Stats (this institution) */}
                <div className="grid grid-cols-3 gap-4">
                    <StatCard label="Total Broadcasts" value={stats.total_broadcasts ?? 0} />
                    <StatCard label="Total Recipients Reached" value={stats.total_recipients ?? 0} />
                    <StatCard label="Last Sent" value={stats.last_sent || 'Never'} small />
                </div>

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-12 lg:items-start">
                    {/* Composer */}
                    <form
                        onSubmit={submit}
                        className="space-y-5 rounded-2xl border border-[var(--border-color)] bg-[var(--surface)] p-6 shadow-xs lg:col-span-7"
                    >
                        <div className="border-b border-[var(--border-color)] pb-4">
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
                                                ? 'border-[var(--accent-soft)] bg-[var(--accent-soft)] text-[var(--accent)]'
                                                : 'border-[var(--border-color)] text-slate-600 hover:bg-[var(--surface-soft)]'
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
                                                : 'border-[var(--border-color)] text-slate-600 hover:bg-[var(--surface-soft)]'
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
                                placeholder="e.g. Menu change from Monday"
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
                                className="rounded-lg border border-[var(--border-color)] px-4 py-2.5 text-sm font-medium text-slate-700 transition-colors hover:bg-[var(--surface-soft)]"
                            >
                                {preview ? 'Hide' : 'Show'} preview
                            </button>
                        </div>
                    </form>

                    {/* Preview */}
                    <div className="lg:col-span-5 lg:sticky lg:top-6">
                        <div className="rounded-2xl border border-[var(--border-color)] bg-[var(--surface)] p-5 shadow-xs">
                            <h4 className="mb-4 text-xs font-bold uppercase tracking-wider text-slate-400">
                                Recipient Preview
                            </h4>
                            {preview ? (
                                <div className="rounded-xl border border-[var(--border-color)] p-4">
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
                                                {institution?.name || 'Institution'} broadcast · just now
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

                {/* History (this institution) */}
                <section className="rounded-2xl border border-[var(--border-color)] bg-[var(--surface)] shadow-xs">
                    <div className="border-b border-[var(--border-color)] px-6 py-4">
                        <h3 className="text-base font-bold text-slate-900">Broadcast History</h3>
                        <p className="mt-0.5 text-xs text-slate-500">
                            Everything sent to this institution. Other workspaces are never shown here.
                        </p>
                    </div>
                    <div className="divide-y divide-[var(--border-color)]">
                        {history.map((b) => (
                            <div key={b.id} className="flex items-start gap-4 px-6 py-4">
                                <span className={`mt-1.5 h-2.5 w-2.5 flex-shrink-0 rounded-full ${severityTone[b.severity]?.dot || 'bg-slate-400'}`} />
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <p className="text-sm font-bold text-slate-800">{b.title}</p>
                                        <span className={`rounded-full border px-2 py-0.5 text-[10px] font-bold ${severityTone[b.severity]?.chip || 'border-[var(--border-color)] bg-[var(--surface-muted)] text-slate-600'}`}>
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
        <div className="rounded-2xl border border-[var(--border-color)] bg-[var(--surface)] p-5 shadow-xs">
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
