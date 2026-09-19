import React from 'react';
import SettingsLayout from '@/Layouts/SettingsLayout';
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
import { Bar, Line, Doughnut } from 'react-chartjs-2';
import { barOptions, doughnutOptions, dualAxisOptions } from '@/Pages/Settings/monitoringCharts';

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

/**
 * Software Super Admin - Global SaaS Business Analytics.
 *
 * Platform-wide FINANCIAL + GROWTH analytics (subscription revenue, conversion,
 * retention and tenant sizing) - never a single institution's meal counts. A
 * tenant's own meal analytics stays inside that tenant's workspace.
 */
export default function Analytics({
    kpis = {},
    trend = [],
    conversion = {},
    planBreakdown = [],
    tenantSize = {},
    topRevenue = [],
    months = 12,
}) {
    const money = useMoney();

    return (
        <SettingsLayout title="SaaS Analytics">
            <Head title="SaaS Analytics" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-col gap-3 rounded-2xl bg-gradient-to-br from-slate-900 to-slate-800 p-6 text-white shadow-sm sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p className="text-[11px] font-bold uppercase tracking-wider text-slate-300">
                            Software Super Admin
                        </p>
                        <h1 className="mt-1 text-xl font-bold">Global SaaS Business Analytics</h1>
                        <p className="mt-1 text-xs text-slate-300">
                            Subscription revenue, conversion and growth across the entire platform.
                        </p>
                    </div>
                    <Link
                        href={route('ssa.dashboard')}
                        className="inline-flex flex-shrink-0 items-center justify-center rounded-xl bg-white/10 px-4 py-2 text-xs font-semibold text-white ring-1 ring-white/20 transition-colors hover:bg-white/20"
                    >
                        Back to Dashboard
                    </Link>
                </div>

                {/* Revenue KPIs */}
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <Kpi label="Monthly Recurring (MRR)" value={money(kpis.mrr)} tone="emerald" sub={`From ${kpis.paid_count} paid tenants`} />
                    <Kpi label="Annual Run-rate (ARR)" value={money(kpis.arr)} sub={`Potential ${money(kpis.potential_arr)}`} />
                    <Kpi label="Avg Revenue / Tenant (ARPA)" value={money(kpis.arpa)} sub="Per paying institution" />
                    <Kpi label="Revenue at Risk" value={money(kpis.revenue_at_risk)} tone="rose" sub="Overdue + pending" />
                </div>

                {/* Conversion KPIs */}
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <Kpi label="Trial to Paid Conversion" value={`${kpis.conversion_pct ?? 0}%`} tone="sky" sub={`${kpis.trial_count} on trial, ${kpis.expired_count} lapsed`} />
                    <Kpi label="Churn" value={`${kpis.churn_pct ?? 0}%`} tone="rose" sub="Suspended / cancelled" />
                    <Kpi label="Paid Tenants" value={kpis.paid_count} tone="emerald" sub={`${kpis.total_institutions} total`} />
                    <Kpi label="On Trial" value={kpis.trial_count} tone="sky" sub={`${kpis.expired_count} expired`} />
                </div>

                {/* Revenue + growth trend */}
                <ChartCard title="Revenue & Customer Growth" subtitle={`Subscription run-rate and new signups over ${months} months`}>
                    <div className="h-72">
                        <Line
                            data={{
                                labels: trend.map((t) => t.label),
                                datasets: [
                                    {
                                        label: 'MRR',
                                        data: trend.map((t) => t.revenue),
                                        borderColor: '#10b981',
                                        backgroundColor: 'rgba(16,185,129,0.12)',
                                        fill: true,
                                        tension: 0.35,
                                        pointRadius: 3,
                                        yAxisID: 'y',
                                    },
                                    {
                                        label: 'New institutions',
                                        data: trend.map((t) => t.new_institutions),
                                        borderColor: '#6366f1',
                                        borderDash: [5, 4],
                                        tension: 0.35,
                                        pointRadius: 2,
                                        yAxisID: 'y1',
                                    },
                                ],
                            }}
                            options={dualAxisOptions}
                        />
                    </div>
                </ChartCard>

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <ChartCard title="Conversion Funnel" subtitle="Registered to trial to converted to paid">
                        <div className="h-64">
                            <Bar
                                data={{
                                    labels: conversion.labels || [],
                                    datasets: [
                                        {
                                            label: 'Institutions',
                                            data: conversion.values || [],
                                            backgroundColor: ['#94a3b8', '#0ea5e9', '#f59e0b', '#10b981'],
                                            borderRadius: 6,
                                        },
                                    ],
                                }}
                                options={noLegendBarOptions}
                            />
                        </div>
                    </ChartCard>

                    <ChartCard title="Revenue by Plan" subtitle="Which plans carry the revenue">
                        <div className="h-64">
                            {planBreakdown.length > 0 ? (
                                <Doughnut
                                    data={{
                                        labels: planBreakdown.map((p) => p.plan),
                                        datasets: [
                                            {
                                                data: planBreakdown.map((p) => p.mrr),
                                                backgroundColor: ['#6366f1', '#10b981', '#f59e0b', '#f43f5e', '#0ea5e9', '#8b5cf6'],
                                                borderWidth: 0,
                                            },
                                        ],
                                    }}
                                    options={doughnutOptions}
                                />
                            ) : (
                                <EmptyMessage>No plans assigned yet.</EmptyMessage>
                            )}
                        </div>
                    </ChartCard>

                    <ChartCard title="Tenant Size Mix" subtitle="Institutions by member count">
                        <div className="h-64">
                            <Doughnut
                                data={{
                                    labels: tenantSize.labels || [],
                                    datasets: [
                                        {
                                            data: tenantSize.values || [],
                                            backgroundColor: ['#0ea5e9', '#6366f1', '#f59e0b', '#10b981'],
                                            borderWidth: 0,
                                        },
                                    ],
                                }}
                                options={doughnutOptions}
                            />
                        </div>
                    </ChartCard>
                </div>

                {/* Top revenue table */}
                <section className="rounded-2xl border-slate-200/80 bg-white shadow-xs">
                    <div className="border-b border-slate-100 px-6 py-4">
                        <h3 className="text-base font-bold text-slate-900">Top Institutions by Revenue</h3>
                        <p className="mt-0.5 text-xs text-slate-500">
                            Highest-value tenants and their ARR contribution.
                        </p>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-100 text-sm">
                            <thead>
                                <tr className="text-left text-[11px] uppercase tracking-wider text-slate-400">
                                    <th className="px-6 py-3 font-bold">Institution</th>
                                    <th className="px-4 py-3 font-bold">Plan</th>
                                    <th className="px-4 py-3 font-bold">Members</th>
                                    <th className="px-4 py-3 text-right font-bold">Monthly</th>
                                    <th className="px-4 py-3 text-right font-bold">ARR</th>
                                    <th className="px-6 py-3 font-bold">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {topRevenue.map((row) => (
                                    <tr key={row.id} className="hover:bg-slate-50/60">
                                        <td className="px-6 py-3 font-semibold text-slate-800">{row.name}</td>
                                        <td className="px-4 py-3 text-slate-600">{row.plan}</td>
                                        <td className="px-4 py-3 text-slate-600">{row.members}</td>
                                        <td className="px-4 py-3 text-right font-semibold text-slate-700">{money(row.amount, true)}</td>
                                        <td className="px-4 py-3 text-right font-semibold text-emerald-600">{money(row.arr)}</td>
                                        <td className="px-6 py-3 text-slate-600">{row.status}</td>
                                    </tr>
                                ))}
                                {topRevenue.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="px-6 py-10 text-center text-sm text-slate-400">
                                            No revenue recorded yet.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </SettingsLayout>
    );
}

/* ------------------------------------------------------------------ *
 * Presentational helpers
 * ------------------------------------------------------------------ */

// Bar options with the legend hidden (single-series funnel).
const noLegendBarOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
    },
    scales: {
        y: {
            beginAtZero: true,
            grid: { color: '#f1f5f9' },
            ticks: { font: { size: 10 } },
        },
        x: {
            grid: { display: false },
            ticks: { font: { size: 10 } },
        },
    },
};

function Kpi({ label, value, sub, tone = 'slate' }) {
    const tones = {
        slate: 'text-slate-900',
        emerald: 'text-emerald-600',
        rose: 'text-rose-600',
        sky: 'text-sky-600',
    };

    return (
        <div className="rounded-2xl border-slate-200/80 bg-white p-5 shadow-xs">
            <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">{label}</p>
            <p className={`mt-1.5 text-2xl font-bold ${tones[tone]}`}>{value}</p>
            {sub && <p className="mt-1 text-[11px] text-slate-400">{sub}</p>}
        </div>
    );
}

function ChartCard({ title, subtitle, children, className = '' }) {
    return (
        <section className={`rounded-2xl border-slate-200/80 bg-white p-6 shadow-xs ${className}`}>
            <h3 className="text-sm font-bold text-slate-900">{title}</h3>
            <p className="mt-0.5 text-xs text-slate-500">{subtitle}</p>
            <div className="mt-5">{children}</div>
        </section>
    );
}

function EmptyMessage({ children }) {
    return (
        <div className="flex h-full items-center justify-center text-sm text-slate-400">{children}</div>
    );
}
