import React, { useState } from 'react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import Modal from '@/Components/UI/Modal';
import { Head, Link, router } from '@inertiajs/react';

/**
 * Email Log / Outbox.
 *
 * Every email dispatched by the platform, with delivery status, recipient,
 * subject, timestamp and the FULL rendered HTML body (opened in a preview
 * modal). Scoped server-side: SSA sees the whole platform, an Institution
 * Admin / Meal Manager sees only their own institution.
 */

const STATUS_TONE = {
    sent: 'border-emerald-100 bg-emerald-50 text-emerald-700',
    pending: 'border-amber-100 bg-amber-50 text-amber-700',
    failed: 'border-rose-100 bg-rose-50 text-rose-700',
};

const KIND_TONE = {
    invitation: 'border-indigo-100 bg-indigo-50 text-indigo-700',
    password_reset: 'border-violet-100 bg-violet-50 text-violet-700',
    announcement: 'border-sky-100 bg-sky-50 text-sky-700',
    general: 'border-slate-200 bg-slate-100 text-slate-600',
};

function StatusChip({ status, label }) {
    return (
        <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold ${STATUS_TONE[status] || 'border-slate-200 bg-slate-100 text-slate-500'}`}>
            {label}
        </span>
    );
}

export default function EmailLog({ logs, stats = {}, kinds = [], institutions = [], isSuperAdmin, scopeInstitution, filters = {} }) {
    const [search, setSearch] = useState(filters?.search || '');
    const [preview, setPreview] = useState(null); // the selected log row
    const [loadingBody, setLoadingBody] = useState(false);

    const rows = logs?.data || [];

    const applyFilters = (next) => {
        router.get(route('settings.emails.index'), { ...filters, ...next }, {
            preserveState: true, preserveScroll: true, replace: true,
        });
    };

    const hasFilters = Boolean(
        filters?.search || filters?.status || filters?.kind || filters?.institution
    );

    const openPreview = async (log) => {
        setPreview({ ...log, body: null });
        setLoadingBody(true);
        try {
            const { data } = await window.axios.get(route('settings.emails.show', log.id));
            setPreview(data.log);
        } catch (e) {
            setPreview(log);
        } finally {
            setLoadingBody(false);
        }
    };

    return (
        <SettingsLayout title="Settings">
            <Head title="Email Log" />

            <div className="space-y-5">
                <div className="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h3 className="text-lg font-bold text-slate-900">Email Log / Outbox</h3>
                        <p className="mt-0.5 text-sm text-slate-500">
                            {isSuperAdmin
                                ? 'Every email dispatched across the platform, with its full rendered content.'
                                : 'Every email your institution has sent, with its full rendered content.'}
                        </p>
                    </div>
                    {scopeInstitution && !isSuperAdmin && (
                        <span className="inline-flex items-center gap-1.5 rounded-full border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-600">
                            <svg className="h-3.5 w-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                            {scopeInstitution.name}
                        </span>
                    )}
                </div>

                {/* Stats */}
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    {[
                        { label: 'Total', value: stats.total ?? 0, tone: 'text-slate-900' },
                        { label: 'Sent', value: stats.sent ?? 0, tone: 'text-emerald-600' },
                        { label: 'Pending', value: stats.pending ?? 0, tone: 'text-amber-600' },
                        { label: 'Failed', value: stats.failed ?? 0, tone: 'text-rose-600' },
                    ].map((card) => (
                        <div key={card.label} className="rounded-xl border-slate-200 bg-white p-4 shadow-sm">
                            <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">{card.label}</div>
                            <div className={`mt-1 text-2xl font-bold ${card.tone}`}>{card.value}</div>
                        </div>
                    ))}
                </div>

                <div className="overflow-hidden rounded-2xl border-slate-200 bg-white shadow-sm">
                    {/* Filters */}
                    <div className="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 lg:flex-row lg:items-center">
                        <form
                            onSubmit={(e) => { e.preventDefault(); applyFilters({ search }); }}
                            className="relative max-w-sm flex-1"
                        >
                            <svg className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                            <input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search recipient or subject..."
                                className="w-full rounded-lg border-slate-300 py-2 pl-9 pr-3 text-sm outline-none transition-all focus:border-[var(--accent)] focus:ring-2 focus:ring-[var(--accent-ring)]"
                            />
                        </form>

                        <select
                            value={filters?.status || ''}
                            onChange={(e) => applyFilters({ status: e.target.value })}
                            className="rounded-lg border-slate-300 text-sm text-slate-900 focus:border-[var(--accent)] focus:ring-[var(--accent)]"
                        >
                            <option value="">All statuses</option>
                            <option value="sent">Sent</option>
                            <option value="pending">Pending</option>
                            <option value="failed">Failed</option>
                        </select>

                        <select
                            value={filters?.kind || ''}
                            onChange={(e) => applyFilters({ kind: e.target.value })}
                            className="rounded-lg border-slate-300 text-sm text-slate-900 focus:border-[var(--accent)] focus:ring-[var(--accent)]"
                        >
                            <option value="">All types</option>
                            {(kinds || []).map((k) => (
                                <option key={k.value} value={k.value}>{k.label}</option>
                            ))}
                        </select>

                        {isSuperAdmin && (
                            <select
                                value={filters?.institution || ''}
                                onChange={(e) => applyFilters({ institution: e.target.value })}
                                className="rounded-lg border-slate-300 text-sm text-slate-900 focus:border-[var(--accent)] focus:ring-[var(--accent)]"
                            >
                                <option value="">All institutions</option>
                                {(institutions || []).map((i) => (
                                    <option key={i.value} value={i.value}>{i.label}</option>
                                ))}
                            </select>
                        )}

                        {hasFilters && (
                            <button
                                type="button"
                                onClick={() => { setSearch(''); router.get(route('settings.emails.index'), {}, { replace: true }); }}
                                className="text-xs font-semibold text-indigo-600 hover:text-indigo-800"
                            >
                                Clear filters
                            </button>
                        )}
                    </div>

                    {/* Table */}
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-180 border-collapse text-left">
                            <thead>
                                <tr className="border-b border-slate-100 bg-slate-50 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                                    <th className="px-5 py-3">When</th>
                                    <th className="px-5 py-3">Recipient</th>
                                    <th className="px-5 py-3">Subject</th>
                                    <th className="px-5 py-3">Type</th>
                                    {isSuperAdmin && <th className="px-5 py-3">Institution</th>}
                                    <th className="px-5 py-3">Status</th>
                                    <th className="px-5 py-3 text-right">View</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 text-sm">
                                {rows.length > 0 ? rows.map((log) => (
                                    <tr key={log.id} className="transition-colors hover:bg-slate-50/60">
                                        <td className="whitespace-nowrap px-5 py-3 text-xs text-slate-500">
                                            <div className="font-medium text-slate-700">{log.created_at}</div>
                                            <div>{log.created_human}</div>
                                        </td>
                                        <td className="px-5 py-3 text-xs text-slate-600">
                                            <div className="max-w-56 truncate font-medium text-slate-700">{log.to || '—'}</div>
                                            {log.triggered_by && (
                                                <div className="text-slate-400">by {log.triggered_by}</div>
                                            )}
                                        </td>
                                        <td className="px-5 py-3">
                                            <div className="max-w-72 truncate font-medium text-slate-800">{log.subject || '(no subject)'}</div>
                                        </td>
                                        <td className="px-5 py-3">
                                            <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold ${KIND_TONE[log.kind] || KIND_TONE.general}`}>
                                                {log.kind_label}
                                            </span>
                                        </td>
                                        {isSuperAdmin && (
                                            <td className="px-5 py-3 text-xs text-slate-500">
                                                {log.institution || <span className="italic text-slate-300">Platform</span>}
                                            </td>
                                        )}
                                        <td className="px-5 py-3">
                                            <StatusChip status={log.status} label={log.status_label} />
                                        </td>
                                        <td className="whitespace-nowrap px-5 py-3 text-right">
                                            <button
                                                type="button"
                                                onClick={() => openPreview(log)}
                                                className="font-semibold text-[var(--accent)] hover:underline"
                                            >
                                                Open
                                            </button>
                                        </td>
                                    </tr>
                                )) : (
                                    <tr>
                                        <td colSpan={isSuperAdmin ? 7 : 6} className="py-16 text-center">
                                            <p className="text-sm font-semibold text-slate-600">No emails logged yet.</p>
                                            <p className="mt-1 text-xs text-slate-400">
                                                Invitations, resets and announcements will appear here as they are sent.
                                            </p>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* Pagination */}
                    {logs?.links?.length > 3 && (
                        <div className="flex flex-col items-center justify-between gap-3 border-t border-slate-100 px-5 py-4 sm:flex-row">
                            <p className="text-xs text-slate-500">
                                Showing <strong>{logs.from}</strong>–<strong>{logs.to}</strong> of <strong>{logs.total}</strong>
                            </p>
                            <div className="flex flex-wrap gap-1">
                                {logs.links.map((link, index) => (
                                    <Link
                                        key={index}
                                        href={link.url || '#'}
                                        preserveScroll
                                        className={`rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors ${link.active
                                            ? 'border-[var(--accent)] bg-[var(--accent)] text-white'
                                            : link.url
                                                ? 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
                                                : 'pointer-events-none border-slate-100 bg-white text-slate-300'
                                            }`}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {/* Email preview: the full rendered HTML, exactly as the recipient saw it. */}
            <Modal
                open={Boolean(preview)}
                onClose={() => setPreview(null)}
                title={preview?.subject || 'Email'}
                description={preview ? `To ${preview.to}${preview.cc ? ` · Cc ${preview.cc}` : ''}` : ''}
                maxWidth="max-w-3xl"
                footer={
                    <button
                        type="button"
                        onClick={() => setPreview(null)}
                        className="rounded-lg border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-100"
                    >
                        Close
                    </button>
                }
            >
                {preview && (
                    <div className="space-y-4">
                        {/* Envelope summary */}
                        <div className="grid grid-cols-2 gap-3 text-xs">
                            <div className="rounded-lg border-slate-200 bg-slate-50 p-3">
                                <div className="text-[10px] font-semibold uppercase tracking-wider text-slate-400">From</div>
                                <div className="mt-0.5 truncate font-medium text-slate-700">{preview.from || '—'}</div>
                            </div>
                            <div className="rounded-lg border-slate-200 bg-slate-50 p-3">
                                <div className="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Status</div>
                                <div className="mt-0.5"><StatusChip status={preview.status} label={preview.status_label} /></div>
                            </div>
                        </div>

                        {preview.error && (
                            <div className="rounded-lg border-rose-200 bg-rose-50 p-3 text-xs text-rose-700">
                                <strong className="font-semibold">Delivery error:</strong> {preview.error}
                            </div>
                        )}

                        {/* Rendered HTML body */}
                        <div className="overflow-hidden rounded-xl border-slate-200">
                            <div className="border-b border-slate-100 bg-slate-50 px-4 py-2 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                Rendered Email
                            </div>
                            {loadingBody ? (
                                <div className="py-16 text-center text-sm text-slate-400">Loading email content...</div>
                            ) : preview.body ? (
                                <iframe
                                    title="Email preview"
                                    srcDoc={preview.body}
                                    className="h-[28rem] w-full border-0 bg-white"
                                    sandbox=""
                                />
                            ) : preview.text_body ? (
                                <pre className="max-h-[28rem] overflow-auto whitespace-pre-wrap p-4 text-xs text-slate-700">
                                    {preview.text_body}
                                </pre>
                            ) : (
                                <div className="py-16 text-center text-xs italic text-slate-400">
                                    No content was captured for this email.
                                </div>
                            )}
                        </div>
                    </div>
                )}
            </Modal>
        </SettingsLayout>
    );
}
