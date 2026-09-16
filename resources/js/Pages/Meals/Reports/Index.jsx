import React, { useMemo, useState } from 'react';
import MealsLayout from '@/Layouts/MealsLayout';
import useMoney from '@/Utils/useMoney';
import { Head, router } from '@inertiajs/react';

/* ------------------------------------------------------------------ *
 * Summary card
 * ------------------------------------------------------------------ */

function SummaryCard({ label, value, tone = 'text-slate-900', hint }) {
    return (
        <div className="rounded-xl border-slate-200 bg-white p-4 shadow-sm">
            <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                {label}
            </div>
            <div className={`mt-1 text-xl font-bold ${tone}`}>{value}</div>
            {hint && <div className="mt-0.5 text-xs text-slate-400">{hint}</div>}
        </div>
    );
}

/* ------------------------------------------------------------------ *
 * Page
 * ------------------------------------------------------------------ */

export default function Index({ summary, students, filters }) {
    const money = useMoney();

    // Default the range to the current month so the first load is meaningful.
    const [from, setFrom] = useState(filters?.from || '');
    const [to, setTo] = useState(filters?.to || '');
    const [onlyDues, setOnlyDues] = useState(false);
    const [search, setSearch] = useState('');

    const rows = useMemo(() => {
        let list = students || [];

        if (onlyDues) {
            list = list.filter((student) => student.is_due);
        }

        if (search.trim() !== '') {
            const term = search.trim().toLowerCase();
            list = list.filter(
                (student) =>
                    student.name.toLowerCase().includes(term) ||
                    (student.roll || '').toLowerCase().includes(term)
            );
        }

        return list;
    }, [students, onlyDues, search]);

    const applyRange = (next) => {
        router.get(
            route('meals.reports.index'),
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true, replace: true }
        );
    };

    const hasRange = Boolean(from || to);

    // Pool affordability: are the deposits on hand enough for the meals eaten?
    const poolBalance = summary?.pool_balance ?? 0;
    const poolHealthy = poolBalance >= 0;

    return (
        <MealsLayout
            title="Meal Report"
            description="Per-student balances, meal costs, and what the pool can cover. Adjust the range to close a month."
        >
            <Head title="Meal Report" />

            {/* Date range */}
            <div className="flex flex-col gap-3 rounded-xl border-slate-200 bg-white p-5 shadow-sm sm:flex-row sm:items-end sm:justify-between">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div>
                        <label htmlFor="from" className="mb-1.5 block text-sm font-semibold text-slate-700">
                            From
                        </label>
                        <input
                            id="from"
                            type="date"
                            value={from}
                            onChange={(event) => setFrom(event.target.value)}
                            className="rounded-lg border-slate-300 text-sm text-slate-900 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                        />
                    </div>

                    <div>
                        <label htmlFor="to" className="mb-1.5 block text-sm font-semibold text-slate-700">
                            To
                        </label>
                        <input
                            id="to"
                            type="date"
                            value={to}
                            onChange={(event) => setTo(event.target.value)}
                            className="rounded-lg border-slate-300 text-sm text-slate-900 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                        />
                    </div>

                    <button
                        type="button"
                        onClick={() => applyRange({ from, to })}
                        className="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white shadow-sm transition-all hover:bg-indigo-700 active:bg-indigo-800"
                    >
                        Apply range
                    </button>

                    {hasRange && (
                        <button
                            type="button"
                            onClick={() => {
                                setFrom('');
                                setTo('');
                                router.get(route('meals.reports.index'), {}, { replace: true });
                            }}
                            className="text-xs font-semibold text-indigo-600 transition-colors hover:text-indigo-800"
                        >
                            All time
                        </button>
                    )}
                </div>

                <div className="text-right">
                    <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        Cost per meal
                    </div>
                    <div className="text-sm font-bold text-slate-800">
                        {money(summary?.cost_per_meal ?? 0, false)}
                    </div>
                </div>
            </div>

            {/* Summary */}
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <SummaryCard
                    label="Total Meals"
                    value={summary?.total_meals ?? 0}
                    tone="text-indigo-600"
                    hint={`${summary?.breakfast ?? 0}B · ${summary?.lunch ?? 0}L · ${summary?.dinner ?? 0}D`}
                />
                <SummaryCard
                    label="Total Deposited"
                    value={money(summary?.total_deposits ?? 0, false)}
                    tone="text-emerald-600"
                    hint="Money pooled by students"
                />
                <SummaryCard
                    label="Total Spent"
                    value={money(summary?.total_expenses ?? 0, false)}
                    tone="text-rose-600"
                    hint="Groceries & supplies"
                />
                <SummaryCard
                    label={poolHealthy ? 'Pool Balance' : 'Pool Shortfall'}
                    value={money(Math.abs(poolBalance), false)}
                    tone={poolHealthy ? 'text-emerald-600' : 'text-rose-600'}
                    hint={poolHealthy ? 'Deposits cover spending' : 'Spending exceeds deposits'}
                />
            </div>

            <div className="grid gap-4 sm:grid-cols-3">
                <SummaryCard
                    label="Total Meal Cost"
                    value={money(summary?.total_meal_cost ?? 0, false)}
                    hint="Meals eaten × cost per meal"
                />
                <SummaryCard
                    label="Students With Dues"
                    value={summary?.students_with_dues ?? 0}
                    tone={(summary?.students_with_dues ?? 0) > 0 ? 'text-rose-600' : 'text-slate-900'}
                />
                <SummaryCard
                    label="Total Outstanding"
                    value={money(summary?.total_dues ?? 0, false)}
                    tone={(summary?.total_dues ?? 0) > 0 ? 'text-rose-600' : 'text-slate-900'}
                    hint="Still owed to the mess"
                />
            </div>

            {/* Per-student table */}
            <div className="overflow-hidden rounded-xl border-slate-200 bg-white shadow-sm">
                <div className="flex flex-col gap-3 border-b border-slate-100 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <h4 className="text-sm font-bold text-slate-900">
                        Per-Student Breakdown
                        <span className="ml-2 font-normal text-slate-400">
                            {rows.length} of {(students || []).length}
                        </span>
                    </h4>

                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Filter by name or roll..."
                            className="rounded-lg border-slate-300 px-3 py-1.5 text-sm outline-none transition-all focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                        />

                        <label className="flex items-center gap-2 text-xs font-semibold text-slate-600 select-none">
                            <input
                                type="checkbox"
                                checked={onlyDues}
                                onChange={(event) => setOnlyDues(event.target.checked)}
                                className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                            />
                            Only show dues
                        </label>
                    </div>
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full border-collapse text-left">
                        <thead>
                            <tr className="border-b border-slate-100 bg-slate-50 text-xs font-semibold uppercase tracking-wider text-slate-400">
                                <th className="px-6 py-3">Student</th>
                                <th className="px-6 py-3">Department</th>
                                <th className="px-3 py-3 text-center">B</th>
                                <th className="px-3 py-3 text-center">L</th>
                                <th className="px-3 py-3 text-center">D</th>
                                <th className="px-4 py-3 text-right">Meals</th>
                                <th className="px-4 py-3 text-right">Meal Cost</th>
                                <th className="px-4 py-3 text-right">Deposited</th>
                                <th className="px-6 py-3 text-right">Balance</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 text-sm">
                            {rows.length > 0 ? (
                                rows.map((student) => (
                                    <tr key={student.id} className="transition-colors hover:bg-slate-50/60">
                                        <td className="px-6 py-3">
                                            <div className="font-semibold text-slate-900">
                                                {student.name}
                                            </div>
                                            {student.roll && (
                                                <div className="text-xs text-slate-400">{student.roll}</div>
                                            )}
                                        </td>
                                        <td className="px-6 py-3 text-xs text-slate-500">
                                            {student.department || (
                                                <span className="italic text-slate-300">Unassigned</span>
                                            )}
                                        </td>
                                        <td className="px-3 py-3 text-center text-slate-600">{student.breakfast}</td>
                                        <td className="px-3 py-3 text-center text-slate-600">{student.lunch}</td>
                                        <td className="px-3 py-3 text-center text-slate-600">{student.dinner}</td>
                                        <td className="px-4 py-3 text-right font-semibold text-slate-800">
                                            {student.total_meals}
                                        </td>
                                        <td className="px-4 py-3 text-right text-slate-600">
                                            {money(student.meal_cost, false)}
                                        </td>
                                        <td className="px-4 py-3 text-right text-emerald-600">
                                            {money(student.total_deposits, false)}
                                        </td>
                                        <td className="px-6 py-3 text-right">
                                            <span
                                                className={`inline-block rounded-full border px-2.5 py-0.5 text-xs font-bold ${student.is_due
                                                        ? 'border-rose-100 bg-rose-50 text-rose-700'
                                                        : 'border-emerald-100 bg-emerald-50 text-emerald-700'
                                                    }`}
                                            >
                                                {student.is_due ? '−' : ''}
                                                {money(Math.abs(student.balance), false)}
                                            </span>
                                        </td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td colSpan="9" className="py-14 text-center">
                                        <p className="text-sm font-semibold text-slate-600">
                                            {onlyDues
                                                ? 'No students currently owe anything.'
                                                : 'No data for this range.'}
                                        </p>
                                        <p className="mt-1 text-xs text-slate-400">
                                            Record deposits and meal entries to populate the report.
                                        </p>
                                    </td>
                                </tr>
                            )}
                        </tbody>
                        {rows.length > 0 && (
                            <tfoot>
                                <tr className="border-t border-slate-200 bg-slate-50 font-bold text-slate-800">
                                    <td className="px-6 py-3" colSpan="5">
                                        Totals ({rows.length} students)
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        {rows.reduce((sum, student) => sum + student.total_meals, 0)}
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        {money(rows.reduce((sum, student) => sum + student.meal_cost, 0), false)}
                                    </td>
                                    <td className="px-4 py-3 text-right text-emerald-700">
                                        {money(rows.reduce((sum, student) => sum + student.total_deposits, 0), false)}
                                    </td>
                                    <td className="px-6 py-3 text-right text-indigo-700">
                                        {money(
                                            rows.reduce((sum, student) => sum + Math.abs(student.balance), 0),
                                            false
                                        )}
                                    </td>
                                </tr>
                            </tfoot>
                        )}
                    </table>
                </div>
            </div>
        </MealsLayout>
    );
}
