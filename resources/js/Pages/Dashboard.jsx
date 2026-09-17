import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import useMoney from '@/Utils/useMoney';
import { Head, Link } from '@inertiajs/react';
import {
    Chart as ChartJS,
    ArcElement,
    CategoryScale,
    LinearScale,
    BarElement,
    LineElement,
    PointElement,
    Title,
    Tooltip,
    Legend,
    Filler,
} from 'chart.js';
import { Doughnut, Bar } from 'react-chartjs-2';

ChartJS.register(
    ArcElement,
    CategoryScale,
    LinearScale,
    BarElement,
    LineElement,
    PointElement,
    Filler,
    Title,
    Tooltip,
    Legend
);

/* ------------------------------------------------------------------ *
 * Metric card
 * ------------------------------------------------------------------ */

function MetricCard({ label, value, hint, tone = 'slate', icon }) {
    const tones = {
        slate: 'text-slate-900',
        indigo: 'text-indigo-600',
        emerald: 'text-emerald-600',
        rose: 'text-rose-600',
        amber: 'text-amber-600',
    };

    return (
        <div className="rounded-2xl border-slate-200 bg-white p-5 shadow-sm transition-all hover:shadow-md">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                        {label}
                    </p>
                    <p className={`mt-1.5 truncate text-2xl font-extrabold ${tones[tone] || tones.slate}`}>
                        {value}
                    </p>
                    {hint && <p className="mt-1 text-xs font-medium text-slate-400">{hint}</p>}
                </div>
                {icon && (
                    <div className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-slate-50 text-slate-400">
                        <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" d={icon} />
                        </svg>
                    </div>
                )}
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ *
 * Page
 * ------------------------------------------------------------------ */

export default function Dashboard({
    metrics = {},
    dailyTrend = [],
    expenseBreakdown = [],
    topStudents = [],
    recentTransactions = [],
    hasMealRate = false,
    reconciliation = {},
}) {
    const money = useMoney();
    const [expenseView, setExpenseView] = useState('doughnut');

    const poolHealthy = (metrics.pool_balance ?? 0) >= 0;

    /* --- Daily trend chart: meals as bars, money as a line --- */
    const trendLabels = dailyTrend.map((d) => d.label);

    const trendData = {
        labels: trendLabels,
        datasets: [
            {
                type: 'bar',
                label: 'Meals',
                data: dailyTrend.map((d) => d.meals),
                backgroundColor: '#6366f1',
                borderRadius: 4,
                yAxisID: 'y',
                order: 2,
            },
            {
                type: 'line',
                label: 'Spent',
                data: dailyTrend.map((d) => d.expenses),
                borderColor: '#f43f5e',
                backgroundColor: 'rgba(244,63,94,0.08)',
                fill: true,
                tension: 0.35,
                pointRadius: 2.5,
                yAxisID: 'y1',
                order: 1,
            },
            {
                type: 'line',
                label: 'Deposited',
                data: dailyTrend.map((d) => d.deposits),
                borderColor: '#10b981',
                backgroundColor: 'rgba(16,185,129,0.08)',
                fill: true,
                tension: 0.35,
                pointRadius: 2.5,
                yAxisID: 'y1',
                order: 0,
            },
        ],
    };

    const trendOptions = {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    usePointStyle: true,
                    padding: 16,
                    font: { family: 'Inter, sans-serif', size: 11, weight: '500' },
                    color: '#64748b',
                },
            },
            tooltip: {
                backgroundColor: '#0f172a',
                padding: 12,
                cornerRadius: 10,
                callbacks: {
                    label: (ctx) => {
                        if (ctx.dataset.label === 'Meals') {
                            return ` ${ctx.dataset.label}: ${ctx.parsed.y}`;
                        }
                        return ` ${ctx.dataset.label}: ${money(ctx.parsed.y, false)}`;
                    },
                },
            },
        },
        scales: {
            x: {
                grid: { display: false },
                ticks: { font: { size: 10 }, color: '#94a3b8' },
            },
            y: {
                position: 'left',
                beginAtZero: true,
                grid: { color: '#f1f5f9' },
                ticks: { font: { size: 10 }, color: '#94a3b8', precision: 0 },
                title: { display: true, text: 'Meals', font: { size: 10 }, color: '#94a3b8' },
            },
            y1: {
                position: 'right',
                beginAtZero: true,
                grid: { drawOnChartArea: false },
                ticks: {
                    font: { size: 10 },
                    color: '#94a3b8',
                    callback: (v) => money(v),
                },
                title: { display: true, text: 'Money', font: { size: 10 }, color: '#94a3b8' },
            },
        },
    };

    /* --- Expense breakdown --- */
    const breakdownLabels = expenseBreakdown.map((e) => e.category);
    const breakdownValues = expenseBreakdown.map((e) => e.total);

    const breakdownPalette = [
        '#6366f1', '#f43f5e', '#10b981', '#f59e0b',
        '#0ea5e9', '#8b5cf6', '#14b8a6', '#64748b',
    ];

    const breakdownData = {
        labels: breakdownLabels,
        datasets: [
            {
                data: breakdownValues,
                backgroundColor: breakdownPalette.slice(0, breakdownLabels.length),
                borderWidth: 2,
                borderColor: '#ffffff',
            },
        ],
    };

    const breakdownOptions = {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '68%',
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    usePointStyle: true,
                    padding: 12,
                    boxWidth: 8,
                    font: { size: 11 },
                    color: '#64748b',
                },
            },
            tooltip: {
                backgroundColor: '#0f172a',
                padding: 12,
                cornerRadius: 10,
                callbacks: {
                    label: (ctx) => ` ${ctx.label}: ${money(ctx.parsed, false)}`,
                },
            },
        },
    };

    const totalBreakdown = breakdownValues.reduce((a, b) => a + b, 0);

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <h2 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">
                        Meal &amp; Expense Overview
                    </h2>
                    <p className="mt-0.5 text-xs font-medium text-slate-500">
                        Shared pool, meal counts, and spending at a glance — {metrics.month_label}
                    </p>
                </div>
            }
        >
            <Head title="Dashboard" />

            <div className="space-y-6">
                {/* Data reconciliation notice */}
                {reconciliation.has_drift && (
                    <div className="flex items-start gap-3 rounded-xl border-sky-200 bg-sky-50 p-4">
                        <svg className="mt-0.5 h-5 w-5 flex-shrink-0 text-sky-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <div>
                            <p className="text-sm font-semibold text-sky-800">
                                Ledger and meal records differ
                            </p>
                            <p className="mt-0.5 text-xs leading-relaxed text-sky-700">
                                The meal module has recorded{' '}
                                <strong>{money(reconciliation.module_deposits ?? 0, false)}</strong> in deposits,
                                but the transaction ledger holds{' '}
                                <strong>{money(reconciliation.ledger_deposits ?? 0, false)}</strong>
                                {' '}({reconciliation.unlinked_deposits ?? 0} deposit(s) are not linked to a
                                transaction). Balances here reflect the ledger; meal reports reflect the module.
                                Both are correct — they are different records.
                            </p>
                        </div>
                    </div>
                )}

                {/* No-rate banner */}
                {!hasMealRate && (
                    <div className="flex items-start gap-3 rounded-xl border-amber-200 bg-amber-50 p-4">
                        <svg className="mt-0.5 h-5 w-5 flex-shrink-0 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                        </svg>
                        <div>
                            <p className="text-sm font-semibold text-amber-800">No meal rate configured</p>
                            <p className="mt-0.5 text-xs text-amber-700">
                                Balances show as deposits only. Set a cost per meal so meal charges can be calculated.
                            </p>
                        </div>
                    </div>
                )}

                {/* Primary metrics */}
                <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <MetricCard
                        label={poolHealthy ? 'Total Pool Balance' : 'Pool Shortfall'}
                        value={money(Math.abs(metrics.pool_balance ?? 0), false)}
                        tone={poolHealthy ? 'emerald' : 'rose'}
                        hint={`${money(metrics.total_deposits ?? 0)} in · ${money(metrics.total_expenses ?? 0)} out`}
                        icon="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"
                    />
                    <MetricCard
                        label="Meals This Month"
                        value={metrics.month_meals ?? 0}
                        tone="indigo"
                        hint={`${metrics.week_meals ?? 0} this week · ${metrics.today_meals ?? 0} today`}
                        icon="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"
                    />
                    <MetricCard
                        label="Spent This Month"
                        value={money(metrics.month_expenses ?? 0, false)}
                        tone="rose"
                        hint={`Collected ${money(metrics.month_deposits ?? 0)}`}
                        icon="M5 10l7-7m0 0l7 7m-7-7v18"
                    />
                    <MetricCard
                        label="Active Students"
                        value={metrics.active_students ?? 0}
                        tone="slate"
                        hint={`${metrics.total_students ?? 0} on the roster`}
                        icon="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-6.93 4 4 0 004 6.93z"
                    />
                </div>

                {/* Secondary metrics */}
                <div className="grid grid-cols-1 gap-5 sm:grid-cols-3">
                    <MetricCard
                        label="Month Meal Cost"
                        value={money(metrics.month_meal_cost ?? 0, false)}
                        tone="indigo"
                        hint={`${metrics.month_meals ?? 0} meals × ${money(metrics.cost_per_meal ?? 0, false)}`}
                    />
                    <MetricCard
                        label="Students With Dues"
                        value={metrics.students_with_dues ?? 0}
                        tone={(metrics.students_with_dues ?? 0) > 0 ? 'rose' : 'emerald'}
                        hint="Owe money to the mess"
                    />
                    <MetricCard
                        label="Total Outstanding"
                        value={money(metrics.total_dues ?? 0, false)}
                        tone={(metrics.total_dues ?? 0) > 0 ? 'rose' : 'emerald'}
                        hint="To be collected"
                    />
                </div>

                {/* Trend chart */}
                <div className="rounded-2xl border-slate-200 bg-white p-6 shadow-sm">
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 className="text-base font-bold text-slate-900">Daily Meal & Money Trend</h3>
                            <p className="text-xs text-slate-500">Meals eaten against money in and out, last 14 days</p>
                        </div>
                    </div>
                    <div className="mt-4 h-72">
                        <Bar data={trendData} options={trendOptions} />
                    </div>
                </div>

                {/* Breakdown + top students */}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-5">
                    {/* Expense breakdown */}
                    <div className="rounded-2xl border-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
                        <div className="flex items-center justify-between">
                            <div>
                                <h3 className="text-base font-bold text-slate-900">Expense Breakdown</h3>
                                <p className="text-xs text-slate-500">This month by category</p>
                            </div>
                            <div className="inline-flex rounded-lg border-slate-200 bg-slate-50 p-0.5">
                                {['doughnut', 'list'].map((view) => (
                                    <button
                                        key={view}
                                        type="button"
                                        onClick={() => setExpenseView(view)}
                                        className={`rounded-md px-2.5 py-1 text-[11px] font-semibold capitalize transition-colors ${
                                            expenseView === view
                                                ? 'bg-white text-slate-900 shadow-sm'
                                                : 'text-slate-500 hover:text-slate-800'
                                        }`}
                                    >
                                        {view}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {breakdownValues.length > 0 ? (
                            expenseView === 'doughnut' ? (
                                <div className="relative mt-4 h-64">
                                    <Doughnut data={breakdownData} options={breakdownOptions} />
                                </div>
                            ) : (
                                <ul className="mt-4 space-y-2">
                                    {expenseBreakdown.map((row, index) => {
                                        const pct = totalBreakdown > 0
                                            ? Math.round((row.total / totalBreakdown) * 100)
                                            : 0;

                                        return (
                                            <li key={row.category} className="space-y-1">
                                                <div className="flex items-center justify-between text-xs">
                                                    <span className="flex items-center gap-2 font-semibold text-slate-700">
                                                        <span
                                                            className="h-2.5 w-2.5 rounded-full"
                                                            style={{
                                                                backgroundColor:
                                                                    breakdownPalette[index % breakdownPalette.length],
                                                            }}
                                                        />
                                                        {row.category}
                                                    </span>
                                                    <span className="font-bold text-slate-800">
                                                        {money(row.total, false)}
                                                        <span className="ml-1.5 font-normal text-slate-400">{pct}%</span>
                                                    </span>
                                                </div>
                                                <div className="h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                                                    <div
                                                        className="h-full rounded-full"
                                                        style={{
                                                            width: `${pct}%`,
                                                            backgroundColor:
                                                                breakdownPalette[index % breakdownPalette.length],
                                                        }}
                                                    />
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )
                        ) : (
                            <p className="mt-10 text-center text-xs italic text-slate-400">
                                No expenses recorded this month.
                            </p>
                        )}
                    </div>

                    {/* Top students by meals */}
                    <div className="overflow-hidden rounded-2xl border-slate-200 bg-white shadow-sm lg:col-span-3">
                        <div className="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                            <div>
                                <h3 className="text-base font-bold text-slate-900">Student Balances</h3>
                                <p className="text-xs text-slate-500">Top meal consumers this month</p>
                            </div>
                            <Link
                                href={route('meals.students.index')}
                                className="text-xs font-semibold text-indigo-600 transition-colors hover:text-indigo-800"
                            >
                                View all
                            </Link>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="w-full border-collapse text-left">
                                <thead>
                                    <tr className="border-b border-slate-100 bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                        <th className="px-6 py-2.5">Student</th>
                                        <th className="px-4 py-2.5 text-right">Meals</th>
                                        <th className="px-4 py-2.5 text-right">Deposited</th>
                                        <th className="px-6 py-2.5 text-right">Balance</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 text-sm">
                                    {topStudents.length > 0 ? (
                                        topStudents.map((student) => (
                                            <tr key={student.id} className="transition-colors hover:bg-slate-50/60">
                                                <td className="px-6 py-3">
                                                    <Link
                                                        href={route('meals.students.show', student.id)}
                                                        className="font-semibold text-slate-900 hover:text-indigo-600"
                                                    >
                                                        {student.name}
                                                    </Link>
                                                    <div className="text-[11px] text-slate-400">
                                                        {student.roll || '—'}
                                                        {student.department && ` · ${student.department}`}
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3 text-right font-semibold text-slate-800">
                                                    {student.meals}
                                                </td>
                                                <td className="px-4 py-3 text-right text-emerald-600">
                                                    {money(student.deposited, false)}
                                                </td>
                                                <td className="px-6 py-3 text-right">
                                                    <span
                                                        className={`inline-block rounded-full border px-2.5 py-0.5 text-xs font-bold ${
                                                            student.balance < 0
                                                                ? 'border-rose-100 bg-rose-50 text-rose-700'
                                                                : 'border-emerald-100 bg-emerald-50 text-emerald-700'
                                                        }`}
                                                    >
                                                        {student.balance < 0 ? '−' : ''}
                                                        {money(Math.abs(student.balance), false)}
                                                    </span>
                                                </td>
                                            </tr>
                                        ))
                                    ) : (
                                        <tr>
                                            <td colSpan="4" className="py-10 text-center text-xs italic text-slate-400">
                                                No students on the roster yet.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                {/* Recent activity */}
                <div className="overflow-hidden rounded-2xl border-slate-200 bg-white shadow-sm">
                    <div className="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                        <div>
                            <h3 className="text-base font-bold text-slate-900">Recent Ledger Activity</h3>
                            <p className="text-xs text-slate-500">Latest money in and out</p>
                        </div>
                        <span className="rounded-full border-slate-200 bg-slate-50 px-2.5 py-0.5 text-xs font-semibold text-slate-500">
                            {recentTransactions.length} entries
                        </span>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full border-collapse text-left">
                            <thead>
                                <tr className="border-b border-slate-100 bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                    <th className="px-6 py-2.5">Date</th>
                                    <th className="px-6 py-2.5">Description</th>
                                    <th className="px-6 py-2.5">Category</th>
                                    <th className="px-6 py-2.5">Counterparty</th>
                                    <th className="px-6 py-2.5 text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 text-sm">
                                {recentTransactions.length > 0 ? (
                                    recentTransactions.map((tx) => (
                                        <tr key={tx.id} className="transition-colors hover:bg-slate-50/60">
                                            <td className="whitespace-nowrap px-6 py-3 text-xs text-slate-500">
                                                {tx.date}
                                            </td>
                                            <td className="px-6 py-3 font-medium text-slate-800">{tx.item}</td>
                                            <td className="px-6 py-3 text-xs text-slate-500">
                                                {tx.category ? (
                                                    <span className="rounded-full bg-slate-100 px-2.5 py-0.5 font-medium text-slate-600">
                                                        {tx.category}
                                                    </span>
                                                ) : (
                                                    <span className="text-slate-300">—</span>
                                                )}
                                            </td>
                                            <td className="px-6 py-3 text-xs text-slate-500">
                                                {tx.type === 'in'
                                                    ? tx.student || '—'
                                                    : tx.payee || '—'}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-3 text-right">
                                                <span
                                                    className={`font-bold ${
                                                        tx.type === 'in' ? 'text-emerald-600' : 'text-rose-600'
                                                    }`}
                                                >
                                                    {tx.type === 'in' ? '+' : '−'}
                                                    {money(tx.amount, false)}
                                                </span>
                                            </td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td colSpan="5" className="py-10 text-center text-xs italic text-slate-400">
                                            No transactions recorded yet.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
