import React, { useState } from 'react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import useCan from '@/Utils/can';
import { Head, Link, router, usePage } from '@inertiajs/react';

/**
 * Audit Trail / Activity Log.
 *
 * Every create, update and delete (plus sign-ins, invitations and exports)
 * lands here with who did it, what changed, and from where. Software Super
 * Admins see every institution; Institution Admins see their own.
 */

const TONES = {
    emerald: 'border-emerald-100 bg-emerald-50 text-emerald-700',
    indigo: 'border-indigo-100 bg-indigo-50 text-indigo-700',
    rose: 'border-rose-100 bg-rose-50 text-rose-700',
    sky: 'border-sky-100 bg-sky-50 text-sky-700',
    slate: 'border-slate-200 bg-slate-100 text-slate-600',
    amber: 'border-amber-100 bg-amber-50 text-amber-700',
    violet: 'border-violet-100 bg-violet-50 text-violet-700',
};

function EventBadge({ event }) {
    const meta = {
        created: ['Created', 'emerald'], updated: ['Updated', 'indigo'],
        deleted: ['Deleted', 'rose'], login: ['Signed in', 'sky'],
        logout: ['Signed out', 'slate'], invited: ['Invited', 'amber'],
        accepted: ['Accepted', 'emerald'], reversed: ['Reversed', 'rose'],
        exported: ['Exported', 'violet'],
    }[event] || [event, 'slate'];

    return (
        <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-bold ${TONES[meta[1]]}`}>
            {meta[0]}
        </span>
    );
}

/** Render a stored diff compactly: only fields that actually changed. */
function ChangeList({ properties }) {
    const changes = properties?.changes;

    if (!changes) return null;

    const entries = Object.entries(changes).slice(0, 4);

    return (
        <div className="mt-2 space-y-1">
            {entries.map(([field, diff]) => (
                <div key={field} className="flex flex-wrap items-baseline gap-1 text-[11px] text-slate-500">
                    <span className="font-semibold text-slate-600">{field}</span>
                    <span className="text-slate-400">{String(diff.old ?? '—')}</span>
                    <span className="text-slate-300">→</span>
                    <span className="font-medium text-slate-700">{String(diff.new ?? '—')}</span>
                </div>
            ))}
            {Object.keys(changes).length > 4 && (
                <div className="text-[11px] italic text-slate-400">
                    +{Object.keys(changes).length - 4} more field(s)
                </div>
            )}
        </div>
    );
}

export default function ActivityLog({ logs, events, filters, isSuperAdmin }) {
    const { flash } = usePage().props;
    const [search, setSearch] = useState(filters?.search || '');

    const rows = logs?.data || [];

    const applyFilters = (next) => {
        router.get(route('settings.activity.index'), { ...filters, ...next }, {
            preserveState: true, preserveScroll: true, replace: true,
        });
    };

    const hasFilters = Boolean(filters?.search || filters?.event || filters?.module || filters?.actor);

    return (
        <SettingsLayout title="Settings">
            <Head title="Activity Log" />

            <div className="space-y-5">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 className="text-lg font-bold text-slate-900">Activity Log</h3>
                        <p className="mt-0.5 text-sm text-slate-500">
                            {isSuperAdmin
                                ? 'Complete audit trail across every institution.'
                                : 'Audit trail for your institution.'}
                        </p>
                    </div>
                </div>

                {flash?.success && (
                    <div role="status" className="rounded-lg border-emerald-200 bg-emerald-50 p-3 text-sm font-medium text-emerald-700">
                        {flash.success}
                    </div>
                )}

                <div className="overflow-hidden rounded-xl border-slate-200 bg-white shadow-sm">
                    <div className="flex flex-col gap-3 border-b border-slate-100 px-4 py-4 sm:flex-row sm:items-center sm:px-6">
                        <form
                            onSubmit={(e) => { e.preventDefault(); applyFilters({ search }); }}
                            className="relative max-w-sm flex-1"
                        >
                            <input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search description, user or subject..."
                                className="w-full rounded-lg border-slate-300 px-3 py-2 text-sm outline-none transition-all focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                            />
                        </form>

                        <select
                            value={filters?.event || ''}
                            onChange={(e) => applyFilters({ event: e.target.value })}
                            className="rounded-lg border-slate-300 text-sm text-slate-900 focus:border-indigo-500 focus:ring-indigo-500"
                        >
                            <option value="">All events</option>
                            {(events || []).map((ev) => (
                                <option key={ev.value} value={ev.value}>{ev.label}</option>
                            ))}
                        </select>

                        {hasFilters && (
                            <button
                                type="button"
                                onClick={() => { setSearch(''); router.get(route('settings.activity.index'), {}, { replace: true }); }}
                                className="text-xs font-semibold text-indigo-600 hover:text-indigo-800 sm:ml-auto"
                            >
                                Clear filters
                            </button>
                        )}
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full min-w-180 border-collapse text-left">
                            <thead>
                                <tr className="border-b border-slate-100 bg-slate-50 text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    <th className="px-4 py-3 sm:px-6">When</th>
                                    <th className="px-4 py-3 sm:px-6">Who</th>
                                    <th className="px-4 py-3 sm:px-6">Event</th>
                                    <th className="px-4 py-3 sm:px-6">What</th>
                                    <th className="px-4 py-3 sm:px-6">Module</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 text-sm">
                                {rows.length > 0 ? rows.map((log) => (
                                    <tr key={log.id} className="align-top transition-colors hover:bg-slate-50/60">
                                        <td className="whitespace-nowrap px-4 py-3 text-xs text-slate-500 sm:px-6">
                                            <div className="font-medium text-slate-700">
                                                {new Date(log.created_at).toLocaleDateString()}
                                            </div>
                                            <div>{new Date(log.created_at).toLocaleTimeString()}</div>
                                        </td>
                                        <td className="px-4 py-3 sm:px-6">
                                            <div className="font-medium text-slate-800">{log.user_name || 'System'}</div>
                                            {log.user_email && (
                                                <div className="text-xs text-slate-400">{log.user_email}</div>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 sm:px-6">
                                            <EventBadge event={log.event} />
                                        </td>
                                        <td className="px-4 py-3 sm:px-6">
                                            <div className="text-slate-700">{log.description}</div>
                                            <ChangeList properties={log.properties} />
                                        </td>
                                        <td className="px-4 py-3 text-xs text-slate-500 sm:px-6">
                                            {log.module}
                                        </td>
                                    </tr>
                                )) : (
                                    <tr>
                                        <td colSpan="5" className="py-14 text-center">
                                            <p className="text-sm font-semibold text-slate-600">No activity recorded yet.</p>
                                            <p className="mt-1 text-xs text-slate-400">
                                                Creates, updates, deletes and sign-ins appear here as they happen.
                                            </p>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {logs?.links?.length > 3 && (
                        <div className="flex items-center justify-between gap-4 border-t border-slate-100 px-4 py-4 sm:px-6">
                            <p className="text-xs text-slate-500">
                                Showing <strong>{logs.from}</strong>–<strong>{logs.to}</strong> of <strong>{logs.total}</strong>
                            </p>
                            <div className="flex flex-wrap gap-1">
                                {logs.links.map((link, index) => (
                                    <Link key={index} href={link.url || '#'} preserveScroll
                                        className={`rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors ${link.active ? 'border-indigo-600 bg-indigo-600 text-white' : link.url ? 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50' : 'pointer-events-none border-slate-100 bg-white text-slate-300'}`}
                                        dangerouslySetInnerHTML={{ __html: link.label }} />
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </SettingsLayout>
    );
}
