import React from 'react';
import MealsLayout from '@/Layouts/MealsLayout';
import useCan from '@/Utils/can';
import useTerminology from '@/Utils/useTerminology';
import { Head, Link, router, usePage } from '@inertiajs/react';

const MEAL_COLUMNS = [
    { field: 'breakfast', label: 'Breakfast', tone: 'text-amber-600' },
    { field: 'lunch', label: 'Lunch', tone: 'text-sky-600' },
    { field: 'dinner', label: 'Dinner', tone: 'text-violet-600' },
];

/**
 * Meal module - Meal Entries list.
 *
 * PURPOSE
 * One row per member per day, counting breakfast / lunch / dinner. These counts
 * feed the per-meal-cost maths everywhere else, so this is the source of truth
 * for "how many meals were eaten". A strip of cards totals the filtered day,
 * then a date/student-filterable table lists the entries.
 *
 * PROPS (from the Laravel controller)
 *  - entries: Laravel paginator { data, links, from, to, total }; each row has
 *    breakfast/lunch/dinner plus a nested `student` and `recorder`.
 *  - students: the roster for the member filter <select>.
 *  - filters: { date, student } echo of the active query - `date` also drives
 *    the "Record Meals" deep link.
 *  - dayTotals: { breakfast, lunch, dinner, total } for the filtered date; only
 *    present when the server computes it, so the cards are conditionally shown.
 *
 * FLOW
 *  - Filtering: applyFilters() issues a partial Inertia visit (preserveState /
 *    preserveScroll / replace) that keeps the page from jumping.
 *  - Record / Edit: there is no inline form here - both actions link to
 *    meals.entries.create with a `date`, where the controller upserts the grid
 *    for that day. Editing simply re-opens that grid for the row's date.
 */
export default function Index({ entries, students, filters, dayTotals }) {
    const { t, tTitle } = useTerminology();
    const { can } = useCan();
    const { flash } = usePage().props;
    const canEntry = can('meals.entry');

    const rows = entries?.data || [];

    const applyFilters = (next) => {
        router.get(
            route('meals.entries.index'),
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true, replace: true }
        );
    };

    return (
        <MealsLayout
            title="Meal Entries"
            description="What each student ate, day by day. Totals feed directly into meal cost calculations."
            actions={
                canEntry && (
                    <Link
                        href={route('meals.entries.create', { date: filters?.date })}
                        className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition-all hover:bg-indigo-700 active:bg-indigo-800"
                    >
                        <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Record Meals
                    </Link>
                )
            }
        >
            <Head title="Meal Entries" />

            {flash?.success && (
                <div
                    role="status"
                    className="flex items-center gap-2 rounded-lg border-emerald-200 bg-emerald-50 p-3 text-sm font-medium text-emerald-700"
                >
                    <svg className="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                    </svg>
                    {flash.success}
                </div>
            )}

            {/* Day totals for the filtered date */}
            {dayTotals && (
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    {MEAL_COLUMNS.map((col) => (
                        <div key={col.field} className="rounded-xl border-slate-200 bg-white p-4 shadow-sm">
                            <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                                {col.label}
                            </div>
                            <div className={`mt-1 text-xl font-bold ${col.tone}`}>
                                {dayTotals[col.field] ?? 0}
                            </div>
                        </div>
                    ))}
                    <div className="rounded-xl border-slate-200 bg-white p-4 shadow-sm">
                        <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                            Total Meals
                        </div>
                        <div className="mt-1 text-xl font-bold text-indigo-600">
                            {dayTotals.total ?? 0}
                        </div>
                    </div>
                </div>
            )}

            <div className="overflow-hidden rounded-xl border-slate-200 bg-white shadow-sm">
                {/* Filters */}
                <div className="flex flex-col gap-3 border-b border-slate-100 px-6 py-4 sm:flex-row sm:items-center">
                    <div>
                        <label htmlFor="date-filter" className="sr-only">Filter by date</label>
                        <input
                            id="date-filter"
                            type="date"
                            value={filters?.date || ''}
                            onChange={(event) => applyFilters({ date: event.target.value })}
                            className="rounded-lg border-slate-300 text-sm text-slate-900 focus:border-indigo-500 focus:ring-indigo-500"
                        />
                    </div>

                    <div>
                        <label htmlFor="student-filter" className="sr-only">Filter by student</label>
                        <select
                            id="student-filter"
                            value={filters?.student || ''}
                            onChange={(event) => applyFilters({ student: event.target.value })}
                            className="rounded-lg border-slate-300 text-sm text-slate-900 focus:border-indigo-500 focus:ring-indigo-500"
                        >
                            <option value="">All {t('members', 'members')}</option>
                            {(students || []).map((student) => (
                                <option key={student.id} value={student.id}>
                                    {student.name}
                                </option>
                            ))}
                        </select>
                    </div>

                    {(filters?.student || filters?.date !== new Date().toISOString().slice(0, 10)) && (
                        <button
                            type="button"
                            onClick={() => router.get(route('meals.entries.index'), {}, { replace: true })}
                            className="text-xs font-semibold text-indigo-600 transition-colors hover:text-indigo-800"
                        >
                            Reset filters
                        </button>
                    )}
                </div>

                {/* Table */}
                <div className="overflow-x-auto">
                    <table className="w-full border-collapse text-left">
                        <thead>
                            <tr className="border-b border-slate-100 bg-slate-50 text-xs font-semibold uppercase tracking-wider text-slate-400">
                                <th className="px-6 py-3">Date</th>
                                <th className="px-6 py-3">{tTitle('member', 'Member')}</th>
                                <th className="px-4 py-3 text-center">B</th>
                                <th className="px-4 py-3 text-center">L</th>
                                <th className="px-4 py-3 text-center">D</th>
                                <th className="px-6 py-3 text-right">Total</th>
                                <th className="px-6 py-3">Recorded By</th>
                                {canEntry && <th className="px-6 py-3 text-right">Actions</th>}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 text-sm">
                            {rows.length > 0 ? (
                                rows.map((entry) => {
                                    const total =
                                        (entry.breakfast || 0) +
                                        (entry.lunch || 0) +
                                        (entry.dinner || 0);

                                    return (
                                        <tr key={entry.id} className="transition-colors hover:bg-slate-50/60">
                                            <td className="px-6 py-3 font-medium text-slate-700">
                                                {new Date(entry.date).toLocaleDateString()}
                                            </td>
                                            <td className="px-6 py-3">
                                                <Link
                                                    href={route('meals.students.show', entry.student_id)}
                                                    className="font-semibold text-slate-900 hover:text-indigo-600"
                                                >
                                                    {entry.student?.name || 'Unknown'}
                                                </Link>
                                                {entry.student?.roll && (
                                                    <span className="ml-2 text-xs text-slate-400">
                                                        {entry.student.roll}
                                                    </span>
                                                )}
                                            </td>
                                            {MEAL_COLUMNS.map((col) => (
                                                <td key={col.field} className="px-4 py-3 text-center">
                                                    <span
                                                        className={
                                                            Number(entry[col.field]) > 0
                                                                ? 'font-semibold text-slate-800'
                                                                : 'text-slate-300'
                                                        }
                                                    >
                                                        {entry[col.field]}
                                                    </span>
                                                </td>
                                            ))}
                                            <td className="px-6 py-3 text-right font-bold text-indigo-600">
                                                {total}
                                            </td>
                                            <td className="px-6 py-3 text-slate-500">
                                                {entry.recorder?.name || '—'}
                                            </td>
                                            {canEntry && (
                                                <td className="whitespace-nowrap px-6 py-3 text-right">
                                                    {/* Editing a meal entry means re-opening the day's
                                                        grid for its date, pre-filled - the controller
                                                        already upserts per (member, day). */}
                                                    <Link
                                                        href={route('meals.entries.create', { date: String(entry.date).slice(0, 10) })}
                                                        className="font-medium text-indigo-600 transition-colors hover:text-indigo-900"
                                                    >
                                                        Edit
                                                    </Link>
                                                </td>
                                            )}
                                        </tr>
                                    );
                                })
                            ) : (
                                <tr>
                                    <td colSpan={canEntry ? 8 : 7} className="py-14 text-center">
                                        <p className="text-sm font-semibold text-slate-600">
                                            No meal entries for this filter.
                                        </p>
                                        <p className="mt-1 text-xs text-slate-400">
                                            Pick a date, or record meals for the day.
                                        </p>
                                        {canEntry && (
                                            <Link
                                                href={route('meals.entries.create', { date: filters?.date })}
                                                className="mt-4 inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
                                            >
                                                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                                                </svg>
                                                Record meals for this day
                                            </Link>
                                        )}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Pagination */}
                {entries?.links?.length > 3 && (
                    <div className="flex items-center justify-between gap-4 border-t border-slate-100 px-6 py-4">
                        <p className="text-xs text-slate-500">
                            Showing <strong>{entries.from}</strong>–<strong>{entries.to}</strong> of{' '}
                            <strong>{entries.total}</strong>
                        </p>
                        <div className="flex flex-wrap gap-1">
                            {entries.links.map((link, index) => (
                                <Link
                                    key={index}
                                    href={link.url || '#'}
                                    preserveScroll
                                    className={`rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors ${link.active
                                            ? 'border-indigo-600 bg-indigo-600 text-white'
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
        </MealsLayout>
    );
}
