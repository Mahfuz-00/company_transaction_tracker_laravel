import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { HelpBadge, PageHint } from '@/Components/Help/HelpHint';
import { Head, router } from '@inertiajs/react';

export default function Review({ schedules = { data: [] } }) {
    const acknowledge = (id) => {
        router.patch(route('meals.schedules.acknowledge', id), {}, {
            preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <h2 className="text-xl font-bold text-slate-900">Member Meal Schedule Queue</h2>
                    <p className="text-xs text-slate-500">Review advance meal attendance declarations submitted by members.</p>
                </div>
            }
        >
            <Head title="Meal Schedule Queue" />

            <div className="space-y-6">
                <PageHint title="Meal Manager Roster Attendance Control">
                    Check declarations before recording daily meals to avoid disputes over missed or off meals.
                </PageHint>

                <div className="rounded-2xl border border-slate-200 bg-white overflow-hidden shadow-sm">
                    <div className="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                        <h3 className="text-sm font-bold text-slate-900">Submitted Member Notices</h3>
                        <HelpBadge title="Attendance Status">
                            When members declare "off", their meal entries can be excluded during those dates to avoid wrongful charges.
                        </HelpBadge>
                    </div>

                    {schedules.data.length === 0 ? (
                        <div className="p-8 text-center text-xs text-slate-500">
                            No member schedule notices found.
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-xs text-slate-600">
                                <thead className="bg-slate-50 border-b border-slate-100 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                    <tr>
                                        <th className="px-6 py-3">Member</th>
                                        <th className="px-4 py-3">Dates</th>
                                        <th className="px-4 py-3">Status</th>
                                        <th className="px-4 py-3">Recurrence</th>
                                        <th className="px-4 py-3">Reason</th>
                                        <th className="px-4 py-3">Decision</th>
                                        <th className="px-6 py-3 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {schedules.data.map((row) => (
                                        <tr key={row.id} className="border-b border-slate-100" data-testid={`schedule-review-row-${row.id}`}>
                                            <td className="px-6 py-3 font-semibold text-slate-800">
                                                {row.student?.name} {row.student?.roll ? `(${row.student.roll})` : ''}
                                            </td>
                                            <td className="px-4 py-3">
                                                {row.starts_on} {row.ends_on && row.ends_on !== row.starts_on ? `→ ${row.ends_on}` : ''}
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className={`rounded-full px-2 py-0.5 text-[10px] font-bold uppercase ${row.status === 'off' ? 'bg-rose-50 text-rose-700 border border-rose-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                                                    }`}>
                                                    {row.status}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 capitalize">{row.recurrence}</td>
                                            <td className="px-4 py-3">{row.reason || '—'}</td>
                                            <td className="px-4 py-3">
                                                <span className="capitalize font-medium text-slate-700">{row.manager_status}</span>
                                            </td>
                                            <td className="px-6 py-3 text-right">
                                                {row.manager_status === 'pending' ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => acknowledge(row.id)}
                                                        data-testid={`acknowledge-schedule-${row.id}`}
                                                        className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-indigo-700"
                                                    >
                                                        Acknowledge
                                                    </button>
                                                ) : (
                                                    <span className="text-[11px] text-slate-400">Acknowledged</span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
