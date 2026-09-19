import React, { useState } from 'react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { Head, Link, router } from '@inertiajs/react';

/**
 * Software Super Admin - Global System Audit & Security Log.
 *
 * A cross-tenant, filterable view of every audited action on the platform,
 * with a severity signal so security/compliance events stand out. Filter by
 * institution, event, severity or date range; export the slice.
 */
export default function Audit({
    logs,
    severityCounts = {},
    failedEmails = 0,
    byInstitution = [],
    institutions = [],
    events = [],
    severities = [],
    filters = {},
}) {
    const [search, setSearch] = useState(filters.search || '');
    const [institution, setInstitution] = useState(filters.institution || '');
    const [event, setEvent] = useState(filters.event || '');
    const [severity, setSeverity] = useState(filters.severity || '');
    const [from, setFrom] = useState(filters.from || '');
    const [to, setTo] = useState(filters.to || '');

    const toneClass = {
        rose: 'bg-rose-50 text-rose-700 border-rose-100',
        amber: 'bg-amber-50 text-amber-700 border-amber-100',
        sky: 'bg-sky-50 text-sky-700 border-sky-100',
        slate: 'bg-slate-100 text-slate-600 border-slate-200',
    };

    const apply = (e) => {
        e?.preventDefault();
        router.get(route('ssa.audit.index'), {
            search,
            institution,
            event,
            severity,
            from,
            to,
        }, { preserveState: true, preserveScroll: true, replace: true });
    };

    const clearFilters = () => {
        setSearch('');
        setInstitution('');
        setEvent('');
        setSeverity('');
        setFrom('');
        setTo('');
        router.get(route('ssa.audit.index'), {}, { replace: true });
    };

    const rows = logs?.data || [];
    const pageLinks = logs?.links || [];

    const exportUrl = (format) => {
        const params = new URLSearchParams();
        params.set('format', format);
        if (institution) params.set('institution', institution);
        if (event) params.set('event', event);
        if (severity) params.set('severity', severity);
        if (from) params.set('from', from);
        if (to) params.set('to', to);
        return `${route('ssa.audit.export')}?${params.toString()}`;
    };

    return (
        <SettingsLayout title="Security & Audit">
            <Head title="Global Audit Log" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-col gap-3 rounded-2xl bg-gradient-to-br from-slate-900 to-slate-800 p-6 text-white shadow-sm sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p className="text-[11px] font-bold uppercase tracking-wider text-slate-300">
                            Software Super Admin
                        </p>
                        <h1 className="mt-1 text-xl font-bold">Global System Audit &amp; Security Log</h1>
                        <p className="mt-1 text-xs text-slate-300">
                            Every audited action across all institutions, filterable for compliance.
                        </p>
                    </div>
                    <div className="flex flex-shrink-0 gap-2">
                        <a
                            href={exportUrl('excel')}
                            className="inline-flex items-center justify-center rounded-xl bg-white/10 px-4 py-2 text-xs font-semibold text-white ring-1 ring-white/20 transition-colors hover:bg-white/20"
                        >
                            Export Excel
                        </a>
                        <a
                            href={exportUrl('pdf')}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center justify-center rounded-xl bg-white/10 px-4 py-2 text-xs font-semibold text-white ring-1 ring-white/20 transition-colors hover:bg-white/20"
                        >
                            PDF
                        </a>
                    </div>
                </div>

                {/* Severity summary */}
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
                    <StatCard label="Critical" value={severityCounts.critical ?? 0} tone="rose" />
                    <StatCard label="Warnings" value={severityCounts.warning ?? 0} tone="amber" />
                    <StatCard label="Info" value={severityCounts.info ?? 0} tone="sky" />
                    <StatCard label="Notices" value={severityCounts.notice ?? 0} tone="slate" />
                    <StatCard label="Failed Emails" value={failedEmails} tone={failedEmails > 0 ? 'rose' : 'slate'} />
                </div>

                {/* Filters */}
                <form onSubmit={apply} className="rounded-2xl border-slate-200/80 bg-white p-5 shadow-xs">
                    <div className="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                        <input
                            type="text"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search description, actor, IP..."
                            className="rounded-lg border-slate-300 px-3.5 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                        />
                        <select
                            value={institution}
                            onChange={(e) => setInstitution(e.target.value)}
                            className="rounded-lg border-slate-300 px-3.5 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                        >
                            <option value="">All institutions</option>
                            {institutions.map((i) => (
                                <option key={i.value} value={i.value}>{i.label}</option>
                            ))}
                        </select>
                        <select
                            value={severity}
                            onChange={(e) => setSeverity(e.target.value)}
                            className="rounded-lg border-slate-300 px-3.5 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                        >
                            <option value="">All severities</option>
                            {severities.map((s) => (
                                <option key={s.value} value={s.value}>{s.label}</option>
                            ))}
                        </select>
                        <select
                            value={event}
                            onChange={(e) => setEvent(e.target.value)}
                            className="rounded-lg border-slate-300 px-3.5 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                        >
                            <option value="">All events</option>
                            {events.map((ev) => (
                                <option key={ev.value} value={ev.value}>{ev.label}</option>
                            ))}
                        </select>
                        <input
                            type="date"
                            value={from}
                            onChange={(e) => setFrom(e.target.value)}
                            className="rounded-lg border-slate-300 px-3.5 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                        />
                        <input
                            type="date"
                            value={to}
                            onChange={(e) => setTo(e.target.value)}
                            className="rounded-lg border-slate-300 px-3.5 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                        />
                    </div>
                    <div className="mt-4 flex items-center gap-2">
                        <button
                            type="submit"
                            className="rounded-lg bg-indigo-600 px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-indigo-700"
                        >
                            Apply filters
                        </button>
                        <button
                            type="button"
                            onClick={clearFilters}
                            className="rounded-lg border-slate-300 px-4 py-2 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50"
                        >
                            Clear
                        </button>
                    </div>
                </form>

                {/* Log table */}
                <section className="rounded-2xl border-slate-200/80 bg-white shadow-xs">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-100 text-sm">
                            <thead>
                                <tr className="text-left text-[11px] uppercase tracking-wider text-slate-400">
                                    <th className="px-6 py-3 font-bold">When</th>
                                    <th className="px-4 py-3 font-bold">Severity</th>
                                    <th className="px-4 py-3 font-bold">Institution</th>
                                    <th className="px-4 py-3 font-bold">Event</th>
                                    <th className="px-4 py-3 font-bold">Description</th>
                                    <th className="px-4 py-3 font-bold">Actor</th>
                                    <th className="px-6 py-3 font-bold">IP</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {rows.map((log) => (
                                    <tr key={log.id} className="hover:bg-slate-50/60">
                                        <td className="whitespace-nowrap px-6 py-3 text-slate-500">
                                            <span className="block font-medium text-slate-700">{log.created_at}</span>
                                            <span className="text-[11px] text-slate-400">{log.created_human}</span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className={`inline-flex rounded-full border px-2.5 py-0.5 text-[10px] font-bold ${toneClass[log.severity_tone] || toneClass.slate}`}>
                                                {log.severity_label}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 font-medium text-slate-700">{log.institution}</td>
                                        <td className="px-4 py-3 text-slate-600">{log.event_label}</td>
                                        <td className="max-w-xs px-4 py-3 text-slate-600">{log.description}</td>
                                        <td className="px-4 py-3 text-slate-600">
                                            <span className="block font-medium text-slate-700">{log.actor}</span>
                                            {log.actor_email && <span className="text-[11px] text-slate-400">{log.actor_email}</span>}
                                        </td>
                                        <td className="px-6 py-3 font-mono text-[11px] text-slate-400">{log.ip || '—'}</td>
                                    </tr>
                                ))}
                                {rows.length === 0 && (
                                    <tr>
                                        <td colSpan={7} className="px-6 py-10 text-center text-sm text-slate-400">
                                            No audit entries match these filters.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* Pagination */}
                    {pageLinks.length > 3 && (
                        <div className="flex flex-wrap items-center gap-1 border-t border-slate-100 px-6 py-3">
                            {pageLinks.map((link, i) => (
                                link.url ? (
                                    <Link
                                        key={i}
                                        href={link.url}
                                        preserveScroll
                                        preserveState
                                        className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors ${link.active
                                                ? 'bg-indigo-600 text-white'
                                                : 'text-slate-600 hover:bg-slate-100'
                                            }`}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ) : (
                                    <span
                                        key={i}
                                        className="rounded-lg px-3 py-1.5 text-xs text-slate-300"
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                )
                            ))}
                        </div>
                    )}
                </section>

                {/* Per-institution roll-up */}
                {byInstitution.length > 0 && (
                    <section className="rounded-2xl border-slate-200/80 bg-white p-6 shadow-xs">
                        <h3 className="text-base font-bold text-slate-900">Activity by Institution</h3>
                        <p className="mt-0.5 text-xs text-slate-500">Where the most audited actions originate.</p>
                        <ul className="mt-5 space-y-3">
                            {byInstitution.map((row) => (
                                <li key={row.id} className="flex items-center justify-between gap-3">
                                    <span className="truncate text-sm font-medium text-slate-700">{row.name}</span>
                                    <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-bold text-slate-600">
                                        {row.entries}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </SettingsLayout>
    );
}

function StatCard({ label, value, tone = 'slate' }) {
    const tones = {
        slate: 'text-slate-900',
        rose: 'text-rose-600',
        amber: 'text-amber-600',
        sky: 'text-sky-600',
    };

    return (
        <div className="rounded-2xl border-slate-200/80 bg-white p-5 shadow-xs">
            <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">{label}</p>
            <p className={`mt-1.5 text-2xl font-bold ${tones[tone]}`}>{value}</p>
        </div>
    );
}
