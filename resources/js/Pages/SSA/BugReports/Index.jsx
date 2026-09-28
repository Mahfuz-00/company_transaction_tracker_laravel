import React, { useMemo, useState } from 'react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { Head, Link, router } from '@inertiajs/react';

/**
 * Software Super Admin — Bug Report Triage Inbox.
 *
 * Every defect a tenant user filed, across EVERY institution, in one queue.
 * This is the one screen in the platform that intentionally shows content from
 * multiple workspaces at once — which is exactly why it is SSA-only (the route
 * carries `role:Software Super Admin`, and the BugReport model is deliberately
 * not tenant-scoped; see its docblock).
 *
 * THE QUEUE IS ORDERED FOR WORKING THROUGH IT
 *   Severity first — critical, then high, then normal, then low — and OLDEST
 *   first within a band. A report that has been waiting longest is the one most
 *   likely to have already cost someone a day, so it wins over a newer report of
 *   the same severity.
 */
export default function BugReportsIndex({
    reports,
    stats = {},
    institutions = [],
    severities = [],
    statuses = [],
    filters = {},
}) {
    // Which report's detail panel is expanded. A single id, because the SSA works
    // through the queue one item at a time.
    const [openId, setOpenId] = useState(null);
    const [resolving, setResolving] = useState(null);
    const [notes, setNotes] = useState('');

    const toneClass = {
        rose: 'bg-rose-50 text-rose-700 border-rose-200',
        amber: 'bg-amber-50 text-amber-700 border-amber-200',
        emerald: 'bg-emerald-50 text-emerald-700 border-emerald-200',
        sky: 'bg-sky-50 text-sky-700 border-sky-200',
        slate: 'bg-slate-100 text-slate-600 border-slate-200',
    };

    const tabs = useMemo(
        () => [
            { value: 'open', label: 'Open', count: stats.open },
            { value: 'acknowledged', label: 'Acknowledged', count: stats.acknowledged },
            { value: 'resolved', label: 'Resolved', count: stats.resolved },
            { value: 'all', label: 'All', count: null },
        ],
        [stats]
    );

    /** Re-query with a filter change, keeping the current scroll position. */
    const applyFilter = (patch) => {
        const next = { ...filters, ...patch };

        // Drop empties so the URL stays clean.
        Object.keys(next).forEach((key) => {
            if (next[key] === '' || next[key] === null) delete next[key];
        });

        router.get(route('ssa.bug-reports.index'), next, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    /** Move a report through its lifecycle. */
    const update = (id, status, resolutionNotes = null) => {
        router.patch(
            route('ssa.bug-reports.update', id),
            { status, resolution_notes: resolutionNotes },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setResolving(null);
                    setNotes('');
                },
            }
        );
    };

    const rows = reports?.data ?? [];

    return (
        <SettingsLayout title="Bug Reports">
            <Head title="Bug Reports" />

            <div className="space-y-6" data-testid="bug-reports-inbox">
                {/* Header */}
                <div className="flex flex-col gap-3 rounded-2xl bg-gradient-to-br from-slate-900 to-slate-800 p-6 text-white shadow-sm sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p className="text-[11px] font-bold uppercase tracking-wider text-slate-300">
                            Software Super Admin
                        </p>
                        <h1 className="mt-1 text-xl font-bold">Bug Reports</h1>
                        <p className="mt-1 text-xs text-slate-300">
                            Defects reported by users across every institution.
                        </p>
                    </div>

                    <div className="flex flex-shrink-0 items-center gap-2">
                        {stats.urgent > 0 && (
                            <span
                                data-testid="urgent-badge"
                                className="inline-flex items-center gap-1.5 rounded-xl bg-rose-500/20 px-3 py-2 text-xs font-bold text-rose-100 ring-1 ring-rose-400/30"
                            >
                                <span className="h-1.5 w-1.5 rounded-full bg-rose-400" />
                                {stats.urgent} urgent
                            </span>
                        )}
                        <Link
                            href={route('ssa.dashboard')}
                            className="inline-flex items-center justify-center rounded-xl bg-white/10 px-4 py-2 text-xs font-semibold text-white ring-1 ring-white/20 transition-colors hover:bg-white/20"
                        >
                            Back to Dashboard
                        </Link>
                    </div>
                </div>

                {/* Status tabs */}
                <div className="flex flex-wrap gap-2">
                    {tabs.map((tab) => {
                        const active = (filters.status ?? 'open') === tab.value;

                        return (
                            <button
                                key={tab.value}
                                type="button"
                                onClick={() => applyFilter({ status: tab.value === 'all' ? 'all' : tab.value })}
                                className={`inline-flex items-center gap-2 rounded-xl px-3.5 py-2 text-xs font-semibold transition-colors ${active
                                        ? 'bg-slate-900 text-white'
                                        : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50'
                                    }`}
                            >
                                {tab.label}
                                {tab.count !== null && (
                                    <span
                                        className={`rounded-md px-1.5 py-0.5 text-[10px] font-bold ${active ? 'bg-white/20' : 'bg-slate-100 text-slate-500'
                                            }`}
                                    >
                                        {tab.count}
                                    </span>
                                )}
                            </button>
                        );
                    })}
                </div>

                {/* Filters */}
                <div className="flex flex-wrap gap-3 rounded-xl border border-slate-200 bg-white p-3">
                    <select
                        value={filters.severity ?? ''}
                        onChange={(e) => applyFilter({ severity: e.target.value })}
                        aria-label="Filter by severity"
                        className="rounded-lg border-slate-200 text-xs text-slate-700 focus:border-indigo-500 focus:ring-indigo-500"
                    >
                        <option value="">All severities</option>
                        {severities.map((s) => (
                            <option key={s.value} value={s.value}>
                                {s.label}
                            </option>
                        ))}
                    </select>

                    <select
                        value={filters.institution ?? ''}
                        onChange={(e) => applyFilter({ institution: e.target.value })}
                        aria-label="Filter by institution"
                        className="rounded-lg border-slate-200 text-xs text-slate-700 focus:border-indigo-500 focus:ring-indigo-500"
                    >
                        <option value="">All institutions</option>
                        {institutions.map((i) => (
                            <option key={i.id} value={i.id}>
                                {i.name}
                            </option>
                        ))}
                    </select>
                </div>

                {/* The queue */}
                {rows.length === 0 ? (
                    <div className="rounded-2xl border border-slate-200 bg-white p-10 text-center">
                        <p className="text-sm font-semibold text-slate-700">Nothing here</p>
                        <p className="mt-1 text-xs text-slate-500">
                            No bug reports match the current filters.
                        </p>
                    </div>
                ) : (
                    <div className="space-y-3">
                        {rows.map((report) => {
                            const open = openId === report.id;

                            return (
                                <div
                                    key={report.id}
                                    data-testid="bug-report-row"
                                    className={`overflow-hidden rounded-2xl border bg-white transition-shadow ${report.is_urgent
                                            ? 'border-rose-200 shadow-sm shadow-rose-100'
                                            : 'border-slate-200'
                                        }`}
                                >
                                    {/* Summary row */}
                                    <button
                                        type="button"
                                        onClick={() => setOpenId(open ? null : report.id)}
                                        className="flex w-full items-start gap-3 p-4 text-left transition-colors hover:bg-slate-50"
                                    >
                                        <span className="min-w-0 flex-1">
                                            <span className="flex flex-wrap items-center gap-2">
                                                <span className="text-sm font-bold text-slate-900">
                                                    {report.title}
                                                </span>

                                                <span
                                                    className={`inline-flex items-center rounded-md border px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide ${toneClass[report.severity_tone] ?? toneClass.slate
                                                        }`}
                                                >
                                                    {report.severity_label}
                                                </span>

                                                <span
                                                    className={`inline-flex items-center rounded-md border px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide ${toneClass[report.status_tone] ?? toneClass.slate
                                                        }`}
                                                >
                                                    {report.status_label}
                                                </span>
                                            </span>

                                            <span className="mt-1 block truncate text-xs text-slate-500">
                                                #{report.id} · {report.reporter.name} ({report.reporter.role})
                                                {report.institution ? ` · ${report.institution}` : ''}
                                                {' · '}
                                                {report.created_human}
                                            </span>
                                        </span>

                                        <svg
                                            className={`mt-1 h-4 w-4 flex-shrink-0 text-slate-400 transition-transform ${open ? 'rotate-180' : ''
                                                }`}
                                            fill="none"
                                            stroke="currentColor"
                                            viewBox="0 0 24 24"
                                        >
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 9l-7 7-7-7" />
                                        </svg>
                                    </button>

                                    {/* Detail */}
                                    {open && (
                                        <div className="border-t border-slate-100 bg-slate-50/60 p-4">
                                            <div className="grid gap-4 lg:grid-cols-3">
                                                {/* Report body */}
                                                <div className="space-y-3 lg:col-span-2">
                                                    <div>
                                                        <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                                            Description
                                                        </p>
                                                        <p className="mt-1 whitespace-pre-wrap text-sm text-slate-700">
                                                            {report.description}
                                                        </p>
                                                    </div>

                                                    {report.steps && (
                                                        <div>
                                                            <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                                                Steps to reproduce
                                                            </p>
                                                            <p className="mt-1 whitespace-pre-wrap text-sm text-slate-700">
                                                                {report.steps}
                                                            </p>
                                                        </div>
                                                    )}

                                                    {report.resolution_notes && (
                                                        <div className="rounded-xl border border-emerald-100 bg-emerald-50 p-3">
                                                            <p className="text-[11px] font-bold uppercase tracking-wider text-emerald-700">
                                                                Resolution
                                                                {report.resolved_by ? ` · ${report.resolved_by}` : ''}
                                                            </p>
                                                            <p className="mt-1 whitespace-pre-wrap text-sm text-emerald-800">
                                                                {report.resolution_notes}
                                                            </p>
                                                        </div>
                                                    )}
                                                </div>

                                                {/* Context + actions */}
                                                <div className="space-y-3">
                                                    <div className="rounded-xl border border-slate-200 bg-white p-3">
                                                        <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                                            Context
                                                        </p>

                                                        <dl className="mt-2 space-y-1.5 text-xs">
                                                            <div>
                                                                <dt className="font-semibold text-slate-500">Page</dt>
                                                                <dd className="break-all font-mono text-[11px] text-slate-700">
                                                                    {report.page_url}
                                                                </dd>
                                                            </div>
                                                            <div>
                                                                <dt className="font-semibold text-slate-500">Reporter</dt>
                                                                <dd className="text-slate-700">
                                                                    {report.reporter.name} · {report.reporter.email}
                                                                </dd>
                                                            </div>
                                                            <div>
                                                                <dt className="font-semibold text-slate-500">Role</dt>
                                                                <dd className="text-slate-700">{report.reporter.role}</dd>
                                                            </div>
                                                            <div>
                                                                <dt className="font-semibold text-slate-500">Filed</dt>
                                                                <dd className="text-slate-700">{report.created_at}</dd>
                                                            </div>
                                                            {report.user_agent && (
                                                                <div>
                                                                    <dt className="font-semibold text-slate-500">Browser</dt>
                                                                    <dd className="break-all text-[10px] text-slate-500">
                                                                        {report.user_agent}
                                                                    </dd>
                                                                </div>
                                                            )}
                                                        </dl>

                                                        {/* The screenshot, if one was attached. Opens full
                                                            size in a new tab so the SSA can zoom in. */}
                                                        {report.screenshot_url && (
                                                            <a
                                                                href={report.screenshot_url}
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                className="mt-3 block"
                                                            >
                                                                <img
                                                                    src={report.screenshot_url}
                                                                    alt={report.screenshot_name || 'Attached screenshot'}
                                                                    data-testid="bug-screenshot"
                                                                    className="max-h-40 w-full rounded-lg border border-slate-200 object-cover transition-opacity hover:opacity-90"
                                                                />
                                                                <span className="mt-1 block truncate text-[10px] text-indigo-600">
                                                                    Open full screenshot ↗
                                                                </span>
                                                            </a>
                                                        )}
                                                    </div>

                                                    {/* Actions */}
                                                    {resolving === report.id ? (
                                                        <div className="rounded-xl border border-slate-200 bg-white p-3">
                                                            <textarea
                                                                rows={3}
                                                                value={notes}
                                                                onChange={(e) => setNotes(e.target.value)}
                                                                placeholder="What did you do to resolve it?"
                                                                className="w-full rounded-lg border-slate-200 text-xs text-slate-700 focus:border-indigo-500 focus:ring-indigo-500"
                                                            />
                                                            <div className="mt-2 flex gap-2">
                                                                <button
                                                                    type="button"
                                                                    onClick={() => update(report.id, 'resolved', notes)}
                                                                    className="flex-1 rounded-lg bg-emerald-600 px-3 py-2 text-xs font-bold text-white transition-colors hover:bg-emerald-700"
                                                                >
                                                                    Mark resolved
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    onClick={() => {
                                                                        setResolving(null);
                                                                        setNotes('');
                                                                    }}
                                                                    className="rounded-lg px-3 py-2 text-xs font-semibold text-slate-500 transition-colors hover:bg-slate-100"
                                                                >
                                                                    Cancel
                                                                </button>
                                                            </div>
                                                        </div>
                                                    ) : (
                                                        <div className="flex flex-wrap gap-2">
                                                            {report.status === 'open' && (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => update(report.id, 'acknowledged')}
                                                                    className="rounded-lg bg-amber-500 px-3 py-2 text-xs font-bold text-white transition-colors hover:bg-amber-600"
                                                                >
                                                                    Acknowledge
                                                                </button>
                                                            )}

                                                            {report.status !== 'resolved' && (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => {
                                                                        setResolving(report.id);
                                                                        setNotes(report.resolution_notes ?? '');
                                                                    }}
                                                                    className="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-bold text-white transition-colors hover:bg-emerald-700"
                                                                >
                                                                    Resolve…
                                                                </button>
                                                            )}

                                                            {report.status !== 'dismissed' && (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => update(report.id, 'dismissed')}
                                                                    className="rounded-lg px-3 py-2 text-xs font-semibold text-slate-500 ring-1 ring-slate-200 transition-colors hover:bg-slate-100"
                                                                >
                                                                    Dismiss
                                                                </button>
                                                            )}

                                                            {(report.status === 'resolved' || report.status === 'dismissed') && (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => update(report.id, 'open')}
                                                                    className="rounded-lg px-3 py-2 text-xs font-semibold text-slate-500 ring-1 ring-slate-200 transition-colors hover:bg-slate-100"
                                                                >
                                                                    Reopen
                                                                </button>
                                                            )}
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                )}

                {/* Pagination — only the simple prev/next the queue needs. */}
                {reports?.links && reports.links.length > 3 && (
                    <div className="flex flex-wrap items-center justify-center gap-1">
                        {reports.links.map((link, index) => (
                            <button
                                key={index}
                                type="button"
                                disabled={!link.url}
                                onClick={() => link.url && router.visit(link.url, { preserveScroll: true })}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                                className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors ${link.active
                                        ? 'bg-slate-900 text-white'
                                        : link.url
                                            ? 'text-slate-600 hover:bg-slate-100'
                                            : 'cursor-not-allowed text-slate-300'
                                    }`}
                            />
                        ))}
                    </div>
                )}
            </div>
        </SettingsLayout>
    );
}