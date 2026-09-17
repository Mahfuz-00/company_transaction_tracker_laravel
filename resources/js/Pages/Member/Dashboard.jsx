import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import useMoney from '@/Utils/useMoney';
import useTerminology from '@/Utils/useTerminology';
import { Head, Link, router } from '@inertiajs/react';

/**
 * Member / participant dashboard.
 *
 * Strictly personal: only the signed-in member's own deposits, meals, balance
 * and manager. No pooled institution figures ever appear here.
 */

function StatCard({ label, value, hint, tone = 'text-slate-900', icon }) {
    return (
        <div className="rounded-2xl border-slate-200 bg-white p-5 shadow-sm transition-shadow hover:shadow-md">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">{label}</p>
                    <p className={`mt-1.5 truncate text-2xl font-extrabold ${tone}`}>{value}</p>
                    {hint && <p className="mt-1 text-xs font-medium text-slate-400">{hint}</p>}
                </div>
                {icon && (
                    <div className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-[var(--accent-soft)] text-[var(--accent)]">
                        <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" d={icon} />
                        </svg>
                    </div>
                )}
            </div>
        </div>
    );
}

function StatusChip({ status, label }) {
    const tone = {
        pending: 'border-amber-100 bg-amber-50 text-amber-700',
        approved: 'border-emerald-100 bg-emerald-50 text-emerald-700',
        rejected: 'border-rose-100 bg-rose-50 text-rose-700',
    }[status] || 'border-slate-200 bg-slate-100 text-slate-500';

    return (
        <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold ${tone}`}>
            {label}
        </span>
    );
}

export default function Dashboard({
    hasMemberRecord = true,
    member = {},
    summary = {},
    history = [],
    recentEntries = [],
    recentDeposits = [],
    claims = [],
    claims_pending = 0,
    months = [],
    month = '',
}) {
    const money = useMoney();
    const { t } = useTerminology();

    if (!hasMemberRecord) {
        return (
            <AuthenticatedLayout
                header={<h2 className="text-xl font-bold text-slate-900">My Dashboard</h2>}
            >
                <Head title="My Dashboard" />
                <div className="rounded-2xl border-slate-200 bg-white p-10 text-center shadow-sm">
                    <h3 className="text-base font-bold text-slate-800">Your account is not linked yet</h3>
                    <p className="mx-auto mt-2 max-w-md text-sm text-slate-500">
                        This login is not connected to a {t('member', 'member').toLowerCase()} record. Ask your{' '}
                        {t('meal_manager', 'meal manager').toLowerCase()} to link your account, then reload this page.
                    </p>
                </div>
            </AuthenticatedLayout>
        );
    }

    const owed = Boolean(summary.is_due);

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">
                            Welcome, {member.name}
                        </h2>
                        <p className="mt-0.5 text-xs font-medium text-slate-500">
                            Your personal meals, {t('deposits', 'deposits').toLowerCase()} and balance — {summary.month_label}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Link
                            href={route('claims.index')}
                            className="inline-flex items-center gap-1.5 rounded-lg border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-50"
                        >
                            My Claims
                            {claims_pending > 0 && (
                                <span className="rounded-full bg-amber-100 px-1.5 text-[10px] font-bold text-amber-700">
                                    {claims_pending}
                                </span>
                            )}
                        </Link>
                        <Link
                            href={route('claims.index')}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-[var(--accent)] px-3.5 py-2 text-xs font-bold text-white shadow-sm transition-opacity hover:opacity-90"
                        >
                            <svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                            </svg>
                            Raise a Claim
                        </Link>
                    </div>
                </div>
            }
        >
            <Head title="My Dashboard" />

            <div className="space-y-6">
                {/* Balance hero */}
                <div
                    className={`rounded-2xl border p-6 shadow-sm ${owed ? 'border-rose-200 bg-rose-50/60' : 'border-emerald-200 bg-emerald-50/60'}`}
                >
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p className="text-[11px] font-bold uppercase tracking-wider text-slate-500">
                                {owed ? 'Amount Due' : 'Credit Balance'}
                            </p>
                            <p className={`mt-1 text-4xl font-extrabold tracking-tight ${owed ? 'text-rose-600' : 'text-emerald-600'}`}>
                                {money(Math.abs(summary.balance ?? 0), false)}
                            </p>
                            <p className="mt-1 text-xs font-medium text-slate-500">
                                {owed
                                    ? 'You owe this amount for meals eaten. Clear it with your manager.'
                                    : 'This is your remaining credit in the meal account.'}
                            </p>
                        </div>
                        <div className="flex flex-shrink-0 flex-col gap-1 text-xs text-slate-500 sm:text-right">
                            <span>
                                {t('member', 'Member')}: <strong className="font-semibold text-slate-700">{member.roll || '—'}</strong>
                            </span>
                            {member.department && (
                                <span>
                                    {t('department', 'Group')}: <strong className="font-semibold text-slate-700">{member.department}</strong>
                                </span>
                            )}
                            <span>
                                Managed by:{' '}
                                <strong className="font-semibold text-slate-700">
                                    {member.manager || 'Not assigned'}
                                </strong>
                            </span>
                        </div>
                    </div>
                </div>

                {/* Personal metrics */}
                <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        label={`Lifetime ${t('deposits', 'Deposits')}`}
                        value={money(summary.lifetime_deposits ?? 0, false)}
                        tone="text-emerald-600"
                        hint={`${money(summary.month_deposited ?? 0)} this month`}
                        icon="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33"
                    />
                    <StatCard
                        label="Meals This Month"
                        value={summary.month_meals ?? 0}
                        tone="text-[var(--accent)]"
                        hint={`${summary.lifetime_meals ?? 0} meals all time`}
                        icon="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"
                    />
                    <StatCard
                        label="Meal Cost This Month"
                        value={money(summary.month_meal_cost ?? 0, false)}
                        tone="text-rose-600"
                        hint={`${summary.month_meals ?? 0} × ${money(summary.cost_per_meal ?? 0, false)} per meal`}
                        icon="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                    />
                    <StatCard
                        label="Cost Per Meal"
                        value={money(summary.cost_per_meal ?? 0, false)}
                        tone="text-slate-900"
                        hint="Current rate for your institution"
                        icon="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8"
                    />
                </div>

                {/* Monthly history + recent activity */}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-5">
                    {/* Month-by-month breakdown */}
                    <div className="overflow-hidden rounded-2xl border-slate-200 bg-white shadow-sm lg:col-span-3">
                        <div className="flex flex-col gap-2 border-b border-slate-100 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h3 className="text-base font-bold text-slate-900">Monthly Meal History</h3>
                                <p className="text-xs text-slate-500">Your meals, cost and balance, month by month</p>
                            </div>
                            <select
                                value={month}
                                onChange={(e) =>
                                    router.get(route('member.dashboard'), { month: e.target.value }, { preserveState: true, preserveScroll: true, replace: true })
                                }
                                aria-label="Report month"
                                className="rounded-lg border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 focus:border-[var(--accent)] focus:ring-[var(--accent)]"
                            >
                                {(months || []).map((m) => (
                                    <option key={m.value} value={m.value}>
                                        {m.label}{m.current ? ' (current)' : ''}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="w-full border-collapse text-left">
                                <thead>
                                    <tr className="border-b border-slate-100 bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                        <th className="px-6 py-2.5">Month</th>
                                        <th className="px-4 py-2.5 text-right">Meals</th>
                                        <th className="px-4 py-2.5 text-right">Deposited</th>
                                        <th className="px-4 py-2.5 text-right">Meal Cost</th>
                                        <th className="px-6 py-2.5 text-right">Balance</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 text-sm">
                                    {history.length > 0 ? (
                                        history.map((row) => {
                                            const rowOwed = row.balance < 0;
                                            return (
                                                <tr key={row.month} className="transition-colors hover:bg-slate-50/60">
                                                    <td className="px-6 py-3 font-semibold text-slate-800">{row.label}</td>
                                                    <td className="px-4 py-3 text-right text-slate-600">{row.meals}</td>
                                                    <td className="px-4 py-3 text-right text-emerald-600">
                                                        {money(row.deposited, false)}
                                                    </td>
                                                    <td className="px-4 py-3 text-right text-rose-600">
                                                        {money(row.meal_cost, false)}
                                                    </td>
                                                    <td className={`px-6 py-3 text-right font-bold ${rowOwed ? 'text-rose-600' : 'text-emerald-600'}`}>
                                                        {rowOwed ? '−' : ''}
                                                        {money(Math.abs(row.balance), false)}
                                                    </td>
                                                </tr>
                                            );
                                        })
                                    ) : (
                                        <tr>
                                            <td colSpan="5" className="py-10 text-center text-xs italic text-slate-400">
                                                No history yet.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {/* Recent deposits */}
                    <div className="overflow-hidden rounded-2xl border-slate-200 bg-white shadow-sm lg:col-span-2">
                        <div className="border-b border-slate-100 px-6 py-4">
                            <h3 className="text-base font-bold text-slate-900">Recent {t('deposits', 'Deposits')}</h3>
                            <p className="text-xs text-slate-500">Payments recorded against your account</p>
                        </div>
                        {recentDeposits.length > 0 ? (
                            <ul className="divide-y divide-slate-100">
                                {recentDeposits.map((dep) => (
                                    <li key={dep.id} className={`flex items-center justify-between px-6 py-3 ${dep.reversed ? 'opacity-60' : ''}`}>
                                        <div className="min-w-0">
                                            <div className={`text-sm font-bold ${dep.reversed ? 'text-slate-400 line-through' : 'text-slate-800'}`}>
                                                {money(dep.amount, false)}
                                            </div>
                                            <div className="truncate text-xs text-slate-400">
                                                {dep.method || 'Cash'}{dep.notes ? ` · ${dep.notes}` : ''}
                                            </div>
                                        </div>
                                        <div className="flex-shrink-0 text-right">
                                            <div className="text-xs text-slate-400">{dep.date}</div>
                                            {dep.reversed && (
                                                <span className="text-[10px] font-bold uppercase text-rose-500">Reversed</span>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="px-6 py-10 text-center text-xs italic text-slate-400">
                                No deposits recorded yet.
                            </p>
                        )}
                    </div>
                </div>

                {/* Recent meal entries */}
                <div className="overflow-hidden rounded-2xl border-slate-200 bg-white shadow-sm">
                    <div className="border-b border-slate-100 px-6 py-4">
                        <h3 className="text-base font-bold text-slate-900">Recent Meal Entries</h3>
                        <p className="text-xs text-slate-500">What was recorded for you, most recent first</p>
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
                                {recentEntries.length > 0 ? (
                                    recentEntries.map((entry) => (
                                        <tr key={entry.id} className="transition-colors hover:bg-slate-50/60">
                                            <td className="px-6 py-3 font-medium text-slate-700">{entry.date}</td>
                                            <td className="px-4 py-3 text-center text-slate-600">{entry.breakfast}</td>
                                            <td className="px-4 py-3 text-center text-slate-600">{entry.lunch}</td>
                                            <td className="px-4 py-3 text-center text-slate-600">{entry.dinner}</td>
                                            <td className="px-6 py-3 text-right font-bold text-[var(--accent)]">{entry.total}</td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td colSpan="5" className="py-10 text-center text-xs italic text-slate-400">
                                            No meal entries recorded yet.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* My claims */}
                <div className="overflow-hidden rounded-2xl border-slate-200 bg-white shadow-sm">
                    <div className="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                        <div>
                            <h3 className="text-base font-bold text-slate-900">My Claims</h3>
                            <p className="text-xs text-slate-500">Disputes and expense claims you have submitted</p>
                        </div>
                        <Link
                            href={route('claims.index')}
                            className="text-xs font-semibold text-[var(--accent)] transition-opacity hover:opacity-80"
                        >
                            View all
                        </Link>
                    </div>
                    {claims.length > 0 ? (
                        <ul className="divide-y divide-slate-100">
                            {claims.map((claim) => (
                                <li key={claim.id} className="flex items-center justify-between gap-3 px-6 py-3">
                                    <div className="min-w-0">
                                        <div className="truncate text-sm font-semibold text-slate-800">{claim.title}</div>
                                        <div className="text-xs text-slate-400">
                                            {claim.kind_label} · {claim.created_at}
                                            {claim.amount !== null ? ` · ${money(claim.amount, false)}` : ''}
                                        </div>
                                    </div>
                                    <StatusChip status={claim.status} label={claim.status_label} />
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <div className="px-6 py-10 text-center">
                            <p className="text-xs italic text-slate-400">
                                You have not raised any claims.
                            </p>
                            <Link
                                href={route('claims.index')}
                                className="mt-3 inline-flex items-center gap-1.5 rounded-lg bg-[var(--accent)] px-3.5 py-2 text-xs font-bold text-white transition-opacity hover:opacity-90"
                            >
                                Raise a claim
                            </Link>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
