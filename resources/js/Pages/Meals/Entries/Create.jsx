import React, { useMemo, useState } from 'react';
import MealsLayout from '@/Layouts/MealsLayout';
import useTerminology from '@/Utils/useTerminology';
import { LoadingOverlay, Spinner } from '@/Components/UI/Loading';
import { Head, Link, router, usePage } from '@inertiajs/react';

/**
 * Daily grid: one row per active student, three numeric inputs each.
 * Local state holds the whole day, submitted in a single request.
 */
export default function Create({ date, students, dayTotals }) {
    const { flash } = usePage().props;
    const { t } = useTerminology();

    // Meals already recorded for the selected day, straight from the server.
    // Seeded into the grid below so an existing entry is editable in place.
    const recorded = dayTotals || { breakfast: 0, lunch: 0, dinner: 0, total: 0 };

    const [day, setDay] = useState(date);
    const [rows, setRows] = useState(() =>
        (students || []).map((student) => ({
            student_id: student.id,
            name: student.name,
            roll: student.roll,
            breakfast: student.breakfast,
            lunch: student.lunch,
            dinner: student.dinner,
        }))
    );
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState({});

    const setCell = (studentId, field, value) => {
        const parsed = value === '' ? 0 : Math.max(0, Math.min(10, Number(value)));

        setRows((current) =>
            current.map((row) =>
                row.student_id === studentId ? { ...row, [field]: parsed } : row
            )
        );
    };

    /** Apply one meal column to every student at once - the common case. */
    const setColumn = (field, value) => {
        setRows((current) => current.map((row) => ({ ...row, [field]: value })));
    };

    const totals = useMemo(() => {
        const sum = (field) => rows.reduce((acc, row) => acc + (Number(row[field]) || 0), 0);

        const breakfast = sum('breakfast');
        const lunch = sum('lunch');
        const dinner = sum('dinner');

        return { breakfast, lunch, dinner, total: breakfast + lunch + dinner };
    }, [rows]);

    const submit = (event) => {
        event.preventDefault();
        setProcessing(true);
        setErrors({});

        router.post(
            route('meals.entries.store'),
            { date: day, entries: rows },
            {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
                onError: (errs) => setErrors(errs),
            }
        );
    };

    return (
        <MealsLayout title="Record Meal Entries">
            <Head title="Record Meal Entries" />

            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 className="text-lg font-bold text-slate-900">Daily Meal Grid</h3>
                    <p className="mt-0.5 text-sm text-slate-500">
                        Enter how many of each meal every {t('member', 'member').toLowerCase()} ate. Blank or 0 rows are skipped.
                    </p>
                </div>
                <Link
                    href={route('meals.entries.index')}
                    className="rounded-lg border-slate-300 px-4 py-2 text-center text-sm font-medium text-slate-700 transition-colors hover:bg-slate-100"
                >
                    View history
                </Link>
            </div>

            {flash?.error && (
                <div role="status" className="rounded-lg border-rose-200 bg-rose-50 p-3 text-sm font-medium text-rose-700">
                    {flash.error}
                </div>
            )}

            <form onSubmit={submit} className="space-y-5">
                {/* Date + current meal count for that date, shown up front */}
                <div className="flex flex-col gap-4 rounded-xl border-slate-200 bg-white p-5 shadow-sm sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <label htmlFor="date" className="mb-1.5 block text-sm font-semibold text-slate-700">
                            Date
                        </label>
                        <input
                            id="date"
                            type="date"
                            value={day}
                            onChange={(event) => setDay(event.target.value)}
                            className="rounded-lg border-slate-300 text-sm text-slate-900 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                        />
                        {errors.date && (
                            <p role="alert" className="mt-1 text-xs text-rose-500">{errors.date}</p>
                        )}

                        {/* Existing total for this date, as a quiet inline hint.
                            The full breakdown lives in the "Selected Date Meal"
                            panel to the right of this picker. */}
                        <p className="mt-2 text-[11px] text-slate-400">
                            {recorded.total > 0
                                ? `${recorded.total} meal(s) already recorded for this date`
                                : 'Nothing recorded for this date yet'}
                        </p>
                    </div>

                    <div className="flex-1 sm:max-w-2xl">
                        {/* A clear title naming the date these counts belong to,
                            so the numbers below always have an explicit anchor. */}
                        <div className="mb-2 flex items-baseline justify-between gap-3">
                            <h4 className="text-xs font-bold uppercase tracking-wider text-slate-500">
                                Selected Date Meal
                            </h4>
                            <span className="text-xs font-semibold text-slate-500">
                                {new Date(day).toLocaleDateString(undefined, {
                                    weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
                                })}
                            </span>
                        </div>

                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            {[
                                { label: 'Breakfast', value: totals.breakfast, tone: 'text-amber-600' },
                                { label: 'Lunch', value: totals.lunch, tone: 'text-sky-600' },
                                { label: 'Dinner', value: totals.dinner, tone: 'text-violet-600' },
                                { label: 'Total', value: totals.total, tone: 'text-[var(--accent)]' },
                            ].map((item) => (
                                <div key={item.label} className="rounded-lg border-slate-200 bg-slate-50 px-3 py-2">
                                    <div className="text-[10px] font-semibold uppercase tracking-wider text-slate-400">
                                        {item.label}
                                    </div>
                                    <div className={`text-lg font-bold ${item.tone}`}>{item.value}</div>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>

                {/* Grid */}
                <div className="overflow-hidden rounded-xl border-slate-200 bg-white shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="w-full border-collapse text-left">
                            <thead>
                                <tr className="border-b border-slate-100 bg-slate-50 text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    <th className="px-6 py-3">{t('member', 'Member')}</th>
                                    {[
                                        { field: 'breakfast', label: 'Breakfast' },
                                        { field: 'lunch', label: 'Lunch' },
                                        { field: 'dinner', label: 'Dinner' },
                                    ].map((col) => (
                                        <th key={col.field} className="px-4 py-3 text-center">
                                            <div>{col.label}</div>
                                            <div className="mt-1.5 flex justify-center gap-1">
                                                {[0, 1].map((value) => (
                                                    <button
                                                        key={value}
                                                        type="button"
                                                        onClick={() => setColumn(col.field, value)}
                                                        className="rounded border-slate-200 bg-white px-1.5 py-0.5 text-[10px] font-semibold text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-800"
                                                        title={`Set all ${col.label} to ${value}`}
                                                    >
                                                        {value}
                                                    </button>
                                                ))}
                                            </div>
                                        </th>
                                    ))}
                                    <th className="px-6 py-3 text-right">Row Total</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 text-sm">
                                {rows.length > 0 ? (
                                    rows.map((row) => {
                                        const rowTotal =
                                            (Number(row.breakfast) || 0) +
                                            (Number(row.lunch) || 0) +
                                            (Number(row.dinner) || 0);

                                        return (
                                            <tr key={row.student_id} className="transition-colors hover:bg-slate-50/60">
                                                <td className="px-6 py-3">
                                                    <div className="font-semibold text-slate-900">{row.name}</div>
                                                    {row.roll && (
                                                        <div className="text-xs text-slate-400">{row.roll}</div>
                                                    )}
                                                </td>

                                                {['breakfast', 'lunch', 'dinner'].map((field) => (
                                                    <td key={field} className="px-4 py-3">
                                                        <input
                                                            type="number"
                                                            min="0"
                                                            max="10"
                                                            value={row[field]}
                                                            onChange={(event) =>
                                                                setCell(row.student_id, field, event.target.value)
                                                            }
                                                            aria-label={`${field} for ${row.name}`}
                                                            className={`w-16 rounded-lg border-slate-300 px-2 py-1.5 text-center text-sm outline-none transition-all focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 ${Number(row[field]) > 0
                                                                    ? 'border-indigo-200 bg-indigo-50/50 font-semibold text-indigo-700'
                                                                    : 'text-slate-500'
                                                                }`}
                                                        />
                                                    </td>
                                                ))}

                                                <td className="px-6 py-3 text-right font-bold text-slate-800">
                                                    {rowTotal}
                                                </td>
                                            </tr>
                                        );
                                    })
                                ) : (
                                    <tr>
                                        <td colSpan="5" className="py-14 text-center">
                                            <p className="text-sm font-semibold text-slate-600">
                                                No active students on the roster.
                                            </p>
                                            <p className="mt-1 text-xs text-slate-400">
                                                Add students before recording meals.
                                            </p>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                            {rows.length > 0 && (
                                <tfoot>
                                    <tr className="border-t border-slate-200 bg-slate-50 font-bold text-slate-800">
                                        <td className="px-6 py-3">Day total</td>
                                        <td className="px-4 py-3 text-center">{totals.breakfast}</td>
                                        <td className="px-4 py-3 text-center">{totals.lunch}</td>
                                        <td className="px-4 py-3 text-center">{totals.dinner}</td>
                                        <td className="px-6 py-3 text-right text-indigo-600">{totals.total}</td>
                                    </tr>
                                </tfoot>
                            )}
                        </table>
                    </div>
                </div>

                {/* Actions */}
                <div className="flex items-center justify-end gap-3">
                    <Link
                        href={route('meals.entries.index')}
                        className="rounded-lg border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-100"
                    >
                        Cancel
                    </Link>
                    <button
                        type="submit"
                        disabled={processing || rows.length === 0}
                        className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition-all hover:bg-indigo-700 active:bg-indigo-800 disabled:opacity-50"
                    >
                        {processing && <Spinner className="h-4 w-4" />}
                        {processing ? 'Saving...' : `Save ${totals.total} meal(s)`}
                    </button>
                </div>
            </form>

            <LoadingOverlay show={processing} message="Saving meal entries..." />
        </MealsLayout>
    );
}
