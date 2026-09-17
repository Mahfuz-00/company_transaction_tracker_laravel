import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import useMoney from '@/Utils/useMoney';
import { Head, router } from '@inertiajs/react';
import {
    Chart as ChartJS,
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
import { Bar } from 'react-chartjs-2';

ChartJS.register(CategoryScale, LinearScale, BarElement, LineElement, PointElement, Title, Tooltip, Legend, Filler);

/**
 * Personal analytics for a member.
 *
 * Exclusively this member's own data - never the institution's pooled figures.
 * The history array arrives reversed (newest first), so it is flipped for the
 * left-to-right chronological chart.
 */
export default function Analytics({
    hasMemberRecord = true,
    member = {},
    month = '',
    months = [],
    summary = {},
    mealSplit = {},
    history = [],
}) {
    const money = useMoney();

    if (!hasMemberRecord) {
        return (
            <AuthenticatedLayout header={<h2 className="text-xl font-bold text-slate-900">My Analytics</h2>}>
                <Head title="My Analytics" />
                <div className="rounded-2xl border-slate-200 bg-white p-10 text-center shadow-sm">
                    <h3 className="text-base font-bold text-slate-800">No member record linked</h3>
                    <p className="mx-auto mt-2 max-w-md text-sm text-slate-500">
                        Your login is not yet linked to a member record. Ask your manager to link it.
                    </p>
                </div>
            </AuthenticatedLayout>
        );
    }

    // Chart wants oldest → newest.
    const chronological = [...history].reverse();

    const chartData = {
        labels: chronological.map((r) => r.label),
        datasets: [
            {
                label: 'Meals',
                data: chronological.map((r) => r.meals),
                backgroundColor: 'var(--accent, #4f46e5)',
                borderRadius: 5,
                yAxisID: 'y',
                order: 2,
            },
            {
                type: 'line',
                label: 'Balance',
                data: chronological.map((r) => r.balance),
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

    const chartOptions = {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: {
                position: 'bottom',
                labels: { usePointStyle: true, padding: 16, font: { family: 'Inter, sans-serif', size: 11, weight: '500' }, color: '#64748b' },
            },
            tooltip: {
                backgroundColor: '#0f172a',
                padding: 12,
                cornerRadius: 10,
                callbacks: {
                    label: (ctx) => (ctx.dataset.label === 'Meals'
                        ? ` ${ctx.dataset.label}: ${ctx.parsed.y}`
                        : ` ${ctx.dataset.label}: ${money(ctx.parsed.y, false)}`),
                },
            },
        },
        scales: {
            x: { grid: { display: false }, ticks: { font: { size: 10 }, color: '#94a3b8' } },
            y: { position: 'left', beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { font: { size: 10 }, color: '#94a3b8', precision: 0 } },
            y1: {
                position: 'right',
                grid: { drawOnChartArea: false },
                ticks: { font: { size: 10 }, color: '#94a3b8', callback: (v) => money(v) },
            },
        },
    };

    const splitTotal = (mealSplit.breakfast || 0) + (mealSplit.lunch || 0) + (mealSplit.dinner || 0);

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">My Analytics</h2>
                        <p className="mt-0.5 text-xs font-medium text-slate-500">
                            Your own meal and balance trends — {summary.month_label || month}
                        </p>
                    </div>
                    <select
                        value={month}
                        onChange={(e) =>
                            router.get(route('member.analytics'), { month: e.target.value }, { preserveState: true, preserveScroll: true, replace: true })
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
            <Head title="My Analytics" />

            <div className="space-y-6">
                {/* Current-month metrics */}
                <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="rounded-2xl border-slate-200 bg-white p-5 shadow-sm">
                        <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">Meals This Month</p>
                        <p className="mt-1.5 text-2xl font-extrabold text-[var(--accent)]">{summary.month_meals ?? 0}</p>
                    </div>
                    <div className="rounded-2xl border-slate-200 bg-white p-5 shadow-sm">
                        <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">Meal Cost</p>
                        <p className="mt-1.5 text-2xl font-extrabold text-rose-600">{money(summary.month_meal_cost ?? 0, false)}</p>
                    </div>
                    <div className="rounded-2xl border-slate-200 bg-white p-5 shadow-sm">
                        <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">Deposited</p>
                        <p className="mt-1.5 text-2xl font-extrabold text-emerald-600">{money(summary.month_deposited ?? 0, false)}</p>
                    </div>
                    <div className="rounded-2xl border-slate-200 bg-white p-5 shadow-sm">
                        <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">Balance</p>
                        <p className={`mt-1.5 text-2xl font-extrabold ${(summary.balance ?? 0) < 0 ? 'text-rose-600' : 'text-emerald-600'}`}>
                            {money(Math.abs(summary.balance ?? 0), false)}
                        </p>
                    </div>
                </div>

                {/* Trend chart */}
                <div className="rounded-2xl border-slate-200 bg-white p-6 shadow-sm">
                    <h3 className="text-base font-bold text-slate-900">Meals & Balance Trend</h3>
                    <p className="mt-0.5 text-xs text-slate-500">Last 12 months of your own activity</p>
                    <div className="mt-4 h-72">
                        <Bar data={chartData} options={chartOptions} />
                    </div>
                </div>

                {/* Meal-type split */}
                <div className="rounded-2xl border-slate-200 bg-white p-6 shadow-sm">
                    <h3 className="text-base font-bold text-slate-900">Meal Split</h3>
                    <p className="mt-0.5 text-xs text-slate-500">Breakfast, lunch and dinner this month</p>
                    <div className="mt-4 space-y-3">
                        {[
                            { label: 'Breakfast', value: mealSplit.breakfast ?? 0, tone: 'bg-amber-500' },
                            { label: 'Lunch', value: mealSplit.lunch ?? 0, tone: 'bg-sky-500' },
                            { label: 'Dinner', value: mealSplit.dinner ?? 0, tone: 'bg-violet-500' },
                        ].map((row) => {
                            const pct = splitTotal > 0 ? Math.round((row.value / splitTotal) * 100) : 0;
                            return (
                                <div key={row.label}>
                                    <div className="flex items-center justify-between text-xs">
                                        <span className="font-semibold text-slate-700">{row.label}</span>
                                        <span className="font-bold text-slate-800">
                                            {row.value} <span className="font-normal text-slate-400">{pct}%</span>
                                        </span>
                                    </div>
                                    <div className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                                        <div className={`h-full rounded-full ${row.tone}`} style={{ width: `${pct}%` }} />
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
