import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import useMoney from '@/Utils/useMoney';
import { Head, Link, router } from '@inertiajs/react';

/**
 * A member's own meal entries.
 *
 * Strictly personal: the server scopes every row to the signed-in member, so
 * this page can never surface another member's meals. The month selector
 * re-queries the same scoped endpoint.
 */
export default function Meals({
    hasMemberRecord = true,
    member = {},
    entries,
    totals = {},
    costPerMeal = 0,
    months = [],
    month = '',
}) {
    const money = useMoney();

    if (!hasMemberRecord) {
        return (
            <AuthenticatedLayout header={<h2 className="text-xl font-bold text-slate-900">My Meal Entries</h2>}>
                <Head title="My Meal Entries" />
                <div className="rounded-2xl border-slate-200 bg-white p-10 text-center shadow-sm">
                    <h3 className="text-base font-bold text-slate-800">No member record linked</h3>
                    <p className="mx-auto mt-2 max-w-md text-sm text-slate-500">
                        Your login is not yet linked to a member record. Ask your manager to link it.
                    </p>
                </div>
            </AuthenticatedLayout>
        );
    }

    const rows = entries?.data || [];

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">My Meal Entries</h2>
                        <p className="mt-0.5 text-xs font-medium text-slate-500">
                            What was recorded for you, day by day
                        </p>
                    </div>
                    <select
                        value={month}
                        onChange={(e) =>
                            router.get(route('member.meals'), { month: e.target.value }, { preserveState: true, preserveScroll: true, replace: true })
                        }
                        aria-label="Report month"
                        className="self-start rounded-lg border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 focus:border-[var(--accent)] focus:ring-[var(--accent)]"
                    >
                        {(months || []).map((m) => (
                            <option key={m.value} value={m.value}>
                                {m.label}{m.current ? ' (current)' : ''}
                            </option>
                        ))}
                    </select>
                </div>
            }
        >
            <Head title="My Meal Entries" />

            <div className="space-y-5">
                {/* Month tallies */}
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    {[
                        { label: 'Breakfast', value: totals.breakfast ?? 0, tone: 'text-amber-600' },
                        { label: 'Lunch', value: totals.lunch ?? 0, tone: 'text-sky-600' },
                        { label: 'Dinner', value: totals.dinner ?? 0, tone: 'text-violet-600' },
                        { label: 'Total Meals', value: totals.total ?? 0, tone: 'text-[var(--accent)]' },
                    ].map((card) => (
                        <div key={card.label} className="rounded-2xl border-slate-200 bg-white p-5 shadow-sm">
                            <div className="text-[11px] font-bold uppercase tracking-wider text-slate-400">{card.label}</div>
                            <div className={`mt-1 text-2xl font-extrabold ${card.tone}`}>{card.value}</div>
                        </div>
                    ))}
                </div>

                <div className="overflow-hidden rounded-2xl border-slate-200 bg-white shadow-sm">
                    <div className="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                        <div>
                            <h3 className="text-base font-bold text-slate-900">Entry History</h3>
                            <p className="text-xs text-slate-500">
                                {money(costPerMeal, false)} per meal this month
                            </p>
                        </div>
                        <span className="rounded-full border-slate-200 bg-slate-50 px-2.5 py-0.5 text-xs font-semibold text-slate-500">
                            {entries?.total ?? 0} days
                        </span>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full border-collapse text-left">
                            <thead>
                                <tr className="border-b border-slate-100 bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                    <th className="px-6 py-2.5">Date</th>
                                    <th className="px-4 py-2.5 text-center">Breakfast</th>
                                    <th className="px-4 py-2.5 text-center">Lunch</th>
                                    <th className="px-4 py-2.5 text-center">Dinner</th>
                                    <th className="px-6 py-2.5 text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 text-sm">
                                {rows.length > 0 ? (
                                    rows.map((entry) => (
                                        <tr key={entry.id} className="transition-colors hover:bg-slate-50/60">
                                            <td className="px-6 py-3 font-medium text-slate-700">{entry.date}</td>
                                            <td className="px-4 py-3 text-center">
                                                <span className={entry.breakfast > 0 ? 'font-semibold text-slate-800' : 'text-slate-300'}>{entry.breakfast}</span>
                                            </td>
                                            <td className="px-4 py-3 text-center">
                                                <span className={entry.lunch > 0 ? 'font-semibold text-slate-800' : 'text-slate-300'}>{entry.lunch}</span>
                                            </td>
                                            <td className="px-4 py-3 text-center">
                                                <span className={entry.dinner > 0 ? 'font-semibold text-slate-800' : 'text-slate-300'}>{entry.dinner}</span>
                                            </td>
                                            <td className="px-6 py-3 text-right font-bold text-[var(--accent)]">{entry.total}</td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td colSpan="5" className="py-14 text-center">
                                            <p className="text-sm font-semibold text-slate-600">No meal entries for this month.</p>
                                            <p className="mt-1 text-xs text-slate-400">
                                                If something is missing, raise a claim from My Claims.
                                            </p>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {entries?.links?.length > 3 && (
                        <div className="flex items-center justify-between gap-4 border-t border-slate-100 px-6 py-4">
                            <p className="text-xs text-slate-500">
                                Showing <strong>{entries.from}</strong>–<strong>{entries.to}</strong> of <strong>{entries.total}</strong>
                            </p>
                            <div className="flex flex-wrap gap-1">
                                {entries.links.map((link, index) => (
                                    <Link
                                        key={index}
                                        href={link.url || '#'}
                                        preserveScroll
                                        className={`rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors ${link.active ? 'border-[var(--accent)] bg-[var(--accent)] text-white' : link.url ? 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50' : 'pointer-events-none border-slate-100 bg-white text-slate-300'}`}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
