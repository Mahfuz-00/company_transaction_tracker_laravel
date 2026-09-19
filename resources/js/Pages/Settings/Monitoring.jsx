import React, { useState } from 'react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import useMoney from '@/Utils/useMoney';
import { Head, Link, useForm } from '@inertiajs/react';
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
import { lineOptions, barOptions, doughnutOptions, healthDoughnut } from './monitoringCharts';

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
 * Software Super Admin - Platform Business Monitoring.
 *
 * The cross-tenant control tower for the SaaS operator: institution health,
 * subscription/billing state, platform-wide revenue + throughput trends, and a
 * global audit stream with a one-click export. Only the global role can reach
 * this page (enforced server-side).
 */
export default function Monitoring({
    overview = {},
    institutions = [],
    revenueTrend = [],
    throughput = [],
    growth = {},
    topInstitutions = [],
    recentActivity = [],
    enquiries = [],
    statusOptions = [],
}) {
    const money = useMoney();
    const [editing, setEditing] = useState(null);

    // Actionable landing enquiries (demo requests) awaiting a decision.
    const actionableEnquiries = enquiries.filter((e) => e.status !== 'approved' && e.status !== 'rejected');

    const healthTone = {
        emerald: 'bg-emerald-50 text-emerald-700 border-emerald-100',
        amber: 'bg-amber-50 text-amber-700 border-amber-100',
        rose: 'bg-rose-50 text-rose-700 border-rose-100',
        sky: 'bg-sky-50 text-sky-700 border-sky-100',
        slate: 'bg-slate-100 text-slate-600 border-slate-200',
    };

    return (
        <SettingsLayout title="Platform Monitoring">
            <Head title="Platform Monitoring" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-col gap-3 rounded-2xl bg-gradient-to-br from-slate-900 to-slate-800 p-6 text-white shadow-sm sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p className="text-[11px] font-bold uppercase tracking-wider text-slate-300">
                            Software Super Admin
                        </p>
                        <h1 className="mt-1 text-xl font-bold">Platform Business Oversight</h1>
                        <p className="mt-1 text-xs text-slate-300">
                            Cross-institution health, subscriptions, revenue &amp; throughput across the whole SaaS.
                        </p>
                    </div>
                    <div className="flex flex-shrink-0 flex-wrap gap-2">
                        <Link
                            href={route('settings.trials.index')}
                            className="inline-flex items-center justify-center rounded-xl bg-white/10 px-4 py-2 text-xs font-semibold text-white ring-1 ring-white/20 transition-colors hover:bg-white/20"
                        >
                            Trial Management
                        </Link>
                        <Link
                            href={route('settings.institutions.index')}
                            className="inline-flex items-center justify-center rounded-xl bg-white/10 px-4 py-2 text-xs font-semibold text-white ring-1 ring-white/20 transition-colors hover:bg-white/20"
                        >
                            Institution Registry
                        </Link>
                    </div>
                </div>

                {/* Headline metrics */}
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <Metric label="Active Institutions" value={overview.active_institutions} sub={`${overview.institutions} total registered`} />
                    <Metric label="Monthly Revenue (MRR)" value={money(overview.mrr)} sub={`ARR ${money(overview.arr)}`} tone="emerald" />
                    <Metric label="Total Members" value={overview.total_members} sub={`${overview.total_users} user accounts`} />
                    <Metric
                        label="Overdue Subscriptions"
                        value={overview.overdue}
                        sub={`${overview.paid} paid · ${overview.trialing} on trial · ${overview.pending || 0} pending`}
                        tone={overview.overdue > 0 ? 'rose' : 'slate'}
                    />
                </div>

                {/* Landing enquiries / demo requests - actionable from here. */}
                <section className="rounded-2xl border-slate-200/80 bg-white shadow-xs">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
                        <div>
                            <h3 className="flex items-center gap-2 text-base font-bold text-slate-900">
                                Landing Enquiries
                                {overview.pending_enquiries > 0 && (
                                    <span className="rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-bold text-indigo-700">
                                        {overview.pending_enquiries} new
                                    </span>
                                )}
                            </h3>
                            <p className="mt-0.5 text-xs text-slate-500">
                                Public demo requests - approve one to provision the institution on a trial.
                            </p>
                        </div>
                        <Link
                            href={route('ssa.enquiries.index')}
                            className="rounded-lg bg-slate-900 px-3.5 py-2 text-xs font-semibold text-white transition-colors hover:bg-slate-800"
                        >
                            Manage all enquiries
                        </Link>
                    </div>

                    <div className="divide-y divide-slate-100">
                        {actionableEnquiries.slice(0, 4).map((e) => (
                            <div key={e.id} className="flex flex-wrap items-center gap-3 px-6 py-3.5">
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-semibold text-slate-800">
                                        {e.institution_name || e.name}
                                    </p>
                                    <p className="truncate text-[11px] text-slate-400">
                                        {e.name} · {e.email} · {e.created_human}
                                    </p>
                                </div>
                                <Link
                                    href={route('ssa.enquiries.index')}
                                    className="flex-shrink-0 rounded-lg bg-emerald-600 px-3.5 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-emerald-700"
                                >
                                    Approve &amp; Provision
                                </Link>
                            </div>
                        ))}
                        {actionableEnquiries.length === 0 && (
                            <div className="px-6 py-8 text-center text-sm text-slate-400">
                                No pending enquiries. New demo requests will appear here.
                            </div>
                        )}
                    </div>
                </section>

                {/* Charts */}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <ChartCard title="Subscription Revenue" subtitle="Platform MRR over the last 6 months" className="lg:col-span-2">
                        <div className="h-64">
                            <Line
                                data={{
                                    labels: revenueTrend.map((r) => r.label),
                                    datasets: [
                                        {
                                            label: 'Revenue',
                                            data: revenueTrend.map((r) => r.revenue),
                                            borderColor: '#4f46e5',
                                            backgroundColor: 'rgba(79,70,229,0.12)',
                                            fill: true,
                                            tension: 0.35,
                                            pointRadius: 3,
                                        },
                                    ],
                                }}
                                options={lineOptions}
                            />
                        </div>
                    </ChartCard>

                    <ChartCard title="Tenant Health" subtitle="Institutions by health verdict">
                        <div className="h-64">
                            <Doughnut data={healthDoughnut(institutions)} options={doughnutOptions} />
                        </div>
                    </ChartCard>
                </div>

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <ChartCard title="Data Throughput" subtitle="Money flow (deposits + expenses) per month">
                        <div className="h-64">
                            <Bar
                                data={{
                                    labels: throughput.map((t) => t.label),
                                    datasets: [
                                        { label: 'Deposits', data: throughput.map((t) => t.deposits), backgroundColor: '#10b981' },
                                        { label: 'Expenses', data: throughput.map((t) => t.expenses), backgroundColor: '#f43f5e' },
                                    ],
                                }}
                                options={barOptions}
                            />
                        </div>
                    </ChartCard>

                    <ChartCard title="Business Growth" subtitle="New institutions &amp; members per month">
                        <div className="h-64">
                            <Bar
                                data={{
                                    labels: (growth.months || []).map((m) => m.label),
                                    datasets: [
                                        { label: 'New members', data: (growth.months || []).map((m) => m.new_members), backgroundColor: '#0ea5e9' },
                                        { label: 'New institutions', data: (growth.months || []).map((m) => m.new_institutions), backgroundColor: '#7c3aed' },
                                    ],
                                }}
                                options={barOptions}
                            />
                        </div>
                        <p className="mt-3 text-xs text-slate-500">
                            Member growth this month:{' '}
                            <span className={`font-semibold ${growth.member_mom_pct >= 0 ? 'text-emerald-600' : 'text-rose-600'}`}>
                                {growth.member_mom_pct >= 0 ? '+' : ''}
                                {growth.member_mom_pct}% MoM
                            </span>
                        </p>
                    </ChartCard>
                </div>

                {/* Institution health table */}
                <section className="rounded-2xl border-slate-200/80 bg-white shadow-xs">
                    <div className="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                        <div>
                            <h3 className="text-base font-bold text-slate-900">Institution Registry &amp; Health</h3>
                            <p className="mt-0.5 text-xs text-slate-500">
                                Subscription status, tenant health and active user counts.
                            </p>
                        </div>
                        <Link
                            href={route('settings.monitoring.audit.export', { format: 'excel' })}
                            className="rounded-lg border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50"
                        >
                            Export Global Audit
                        </Link>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-100 text-sm">
                            <thead>
                                <tr className="text-left text-[11px] uppercase tracking-wider text-slate-400">
                                    <th className="px-6 py-3 font-bold">Institution</th>
                                    <th className="px-4 py-3 font-bold">Subscription</th>
                                    <th className="px-4 py-3 font-bold">Health</th>
                                    <th className="px-4 py-3 font-bold">Members</th>
                                    <th className="px-4 py-3 font-bold">Active Users</th>
                                    <th className="px-4 py-3 font-bold">Usage</th>
                                    <th className="px-6 py-3 text-right font-bold">Manage</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {institutions.map((inst) => (
                                    <tr key={inst.id} className="hover:bg-slate-50/60">
                                        <td className="px-6 py-3">
                                            <div className="flex items-center gap-3">
                                                {inst.logo_url ? (
                                                    <img src={inst.logo_url} alt="" className="h-8 w-8 rounded-lg object-contain" />
                                                ) : (
                                                    <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-slate-100 text-xs font-bold text-slate-500">
                                                        {inst.name?.[0]?.toUpperCase()}
                                                    </span>
                                                )}
                                                <div className="min-w-0">
                                                    <p className="truncate font-semibold text-slate-800">{inst.name}</p>
                                                    <p className="text-[11px] text-slate-400">{inst.type_label}</p>
                                                </div>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span
                                                className={`inline-flex rounded-full border px-2.5 py-0.5 text-[10px] font-bold ${healthTone[inst.subscription_tone] || healthTone.slate}`}
                                            >
                                                {inst.subscription_label}
                                            </span>
                                            <p className="mt-1 text-[11px] text-slate-400">
                                                {money(inst.subscription_amount, true)}
                                                {inst.renews_at ? ` · renews ${inst.renews_at}` : ''}
                                            </p>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span
                                                className={`inline-flex rounded-full border px-2.5 py-0.5 text-[10px] font-bold ${healthTone[inst.health?.tone] || healthTone.slate}`}
                                            >
                                                {inst.health?.label}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 font-semibold text-slate-700">{inst.members_count}</td>
                                        <td className="px-4 py-3 text-slate-600">{inst.active_users}</td>
                                        <td className="px-4 py-3">
                                            {inst.usage_percent === null ? (
                                                <span className="text-xs text-slate-400">—</span>
                                            ) : (
                                                <div className="flex items-center gap-2">
                                                    <div className="h-1.5 w-16 overflow-hidden rounded-full bg-slate-100">
                                                        <div
                                                            className={`h-full rounded-full ${inst.usage_percent >= 90 ? 'bg-rose-500' : inst.usage_percent >= 70 ? 'bg-amber-500' : 'bg-emerald-500'}`}
                                                            style={{ width: `${inst.usage_percent}%` }}
                                                        />
                                                    </div>
                                                    <span className="text-[11px] font-medium text-slate-500">
                                                        {inst.members_count}/{inst.member_limit}
                                                    </span>
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-6 py-3 text-right">
                                            <button
                                                type="button"
                                                onClick={() => setEditing(inst)}
                                                className="rounded-lg border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-100"
                                            >
                                                Manage
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                                {institutions.length === 0 && (
                                    <tr>
                                        <td colSpan={7} className="px-6 py-10 text-center text-sm text-slate-400">
                                            No institutions yet.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>

                {/* Top institutions + activity */}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <section className="rounded-2xl border-slate-200/80 bg-white p-6 shadow-xs">
                        <h3 className="text-base font-bold text-slate-900">Top Institutions by Throughput</h3>
                        <p className="mt-0.5 text-xs text-slate-500">
                            Heaviest users vs. what they pay - a signal for pricing.
                        </p>
                        <ul className="mt-5 space-y-3">
                            {topInstitutions.map((inst, i) => (
                                <li key={inst.id} className="flex items-center justify-between gap-3">
                                    <div className="flex min-w-0 items-center gap-3">
                                        <span className="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full bg-slate-100 text-[11px] font-bold text-slate-500">
                                            {i + 1}
                                        </span>
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-semibold text-slate-800">{inst.name}</p>
                                            <p className="text-[11px] text-slate-400">{inst.members} members</p>
                                        </div>
                                    </div>
                                    <div className="text-right">
                                        <p className="text-sm font-bold text-slate-800">{money(inst.deposits)}</p>
                                        <p className="text-[11px] text-slate-400">pays {money(inst.subscription_amount, true)}</p>
                                    </div>
                                </li>
                            ))}
                            {topInstitutions.length === 0 && (
                                <li className="text-sm text-slate-400">No throughput recorded yet.</li>
                            )}
                        </ul>
                    </section>

                    <section className="rounded-2xl border-slate-200/80 bg-white p-6 shadow-xs">
                        <h3 className="text-base font-bold text-slate-900">Recent Platform Activity</h3>
                        <p className="mt-0.5 text-xs text-slate-500">Latest audited events across every institution.</p>
                        <ul className="mt-5 space-y-3">
                            {recentActivity.map((log) => (
                                <li key={log.id} className="flex items-start gap-3 text-sm">
                                    <span className="mt-1.5 h-1.5 w-1.5 flex-shrink-0 rounded-full bg-slate-300" />
                                    <div className="min-w-0">
                                        <p className="truncate text-slate-700">{log.description}</p>
                                        <p className="text-[11px] text-slate-400">
                                            {log.institution} · {log.actor || 'System'} · {log.at}
                                        </p>
                                    </div>
                                </li>
                            ))}
                            {recentActivity.length === 0 && (
                                <li className="text-sm text-slate-400">No activity yet.</li>
                            )}
                        </ul>
                    </section>
                </div>
            </div>

            {editing && (
                <SubscriptionModal
                    institution={editing}
                    statusOptions={statusOptions}
                    onClose={() => setEditing(null)}
                />
            )}
        </SettingsLayout>
    );
}

/* ------------------------------------------------------------------ *
 * Subscription editor modal
 * ------------------------------------------------------------------ */

function SubscriptionModal({ institution, statusOptions, onClose }) {
    const { data, setData, put, processing, errors } = useForm({
        subscription_plan: institution.subscription_plan || '',
        subscription_status: institution.subscription_status || 'trial',
        subscription_amount: institution.subscription_amount || 0,
        subscription_renews_at: '',
        member_limit: institution.member_limit || '',
        health_notes: institution.health_notes || '',
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('settings.monitoring.subscription', institution.id), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-xs">
            <div className="w-full max-w-lg rounded-2xl border-slate-100 bg-white p-6 shadow-xl">
                <h3 className="text-base font-bold text-slate-900">{institution.name}</h3>
                <p className="mt-0.5 text-xs text-slate-500">Subscription &amp; health settings</p>

                <form onSubmit={submit} className="mt-5 space-y-4">
                    <div>
                        <label className="mb-1.5 block text-xs font-semibold text-slate-700">Plan name</label>
                        <input
                            type="text"
                            value={data.subscription_plan}
                            onChange={(e) => setData('subscription_plan', e.target.value)}
                            placeholder="e.g. Growth, Enterprise"
                            className="w-full rounded-xl border-slate-200 px-3.5 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10"
                        />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label className="mb-1.5 block text-xs font-semibold text-slate-700">Status</label>
                            <select
                                value={data.subscription_status}
                                onChange={(e) => setData('subscription_status', e.target.value)}
                                className="w-full rounded-xl border-slate-200 px-3.5 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10"
                            >
                                {statusOptions.map((o) => (
                                    <option key={o.value} value={o.value}>{o.label}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="mb-1.5 block text-xs font-semibold text-slate-700">Monthly amount</label>
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                value={data.subscription_amount}
                                onChange={(e) => setData('subscription_amount', e.target.value)}
                                className="w-full rounded-xl border-slate-200 px-3.5 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10"
                            />
                        </div>
                        <div>
                            <label className="mb-1.5 block text-xs font-semibold text-slate-700">Renews on</label>
                            <input
                                type="date"
                                value={data.subscription_renews_at}
                                onChange={(e) => setData('subscription_renews_at', e.target.value)}
                                className="w-full rounded-xl border-slate-200 px-3.5 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10"
                            />
                        </div>
                        <div>
                            <label className="mb-1.5 block text-xs font-semibold text-slate-700">Member cap</label>
                            <input
                                type="number"
                                min="0"
                                value={data.member_limit}
                                onChange={(e) => setData('member_limit', e.target.value)}
                                placeholder="Unlimited"
                                className="w-full rounded-xl border-slate-200 px-3.5 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10"
                            />
                        </div>
                    </div>

                    <div>
                        <label className="mb-1.5 block text-xs font-semibold text-slate-700">Health notes</label>
                        <textarea
                            rows={3}
                            value={data.health_notes}
                            onChange={(e) => setData('health_notes', e.target.value)}
                            placeholder="Internal notes about this tenant's health or billing."
                            className="w-full rounded-xl border-slate-200 px-3.5 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10"
                        />
                    </div>

                    {Object.keys(errors).length > 0 && (
                        <p className="text-xs text-rose-500">Please check the values and try again.</p>
                    )}

                    <div className="flex justify-end gap-2 pt-2">
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-xl border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={processing}
                            className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 disabled:opacity-50"
                        >
                            {processing ? 'Saving...' : 'Save'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ *
 * Small presentational helpers
 * ------------------------------------------------------------------ */

function Metric({ label, value, sub, tone = 'slate' }) {
    const tones = {
        slate: 'text-slate-900',
        emerald: 'text-emerald-600',
        rose: 'text-rose-600',
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
