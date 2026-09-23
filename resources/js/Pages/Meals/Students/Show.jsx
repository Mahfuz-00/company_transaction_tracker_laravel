import React from 'react';
import MealsLayout from '@/Layouts/MealsLayout';
import useCan from '@/Utils/can';
import useMoney from '@/Utils/useMoney';
import useTerminology from '@/Utils/useTerminology';
import { Head, Link } from '@inertiajs/react';

const initials = (name) =>
    name
        ? name
            .trim()
            .split(/\s+/)
            .map((part) => part[0])
            .slice(0, 2)
            .join('')
            .toUpperCase()
        : '?';

function StatCard({ label, value, tone = 'text-slate-900', hint }) {
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

/**
 * Meal module - Member detail page.
 *
 * PURPOSE
 * The read-only profile for ONE member: identity header, life-time totals
 * (meals, deposits, balance, rate) and the most recent deposits and meal
 * entries side by side. It is reached by clicking a name in the roster.
 *
 * PROPS (from the Laravel controller - a single record, not a paginator)
 *  - student: the member, with nested `deposits`, `entries`, `department`, and
 *    optionally `user` (the linked login account).
 *  - costPerMeal: the active meal rate, or 0/null when none is set.
 *  - balance: life-time balance (negative means the member owes money).
 *  - totalMeals: life-time breakfast + lunch + dinner count.
 *  - totalDeposits: life-time deposits.
 *
 * FLOW
 *  - Pure presentation - no forms or mutations. "Back to list" is an Inertia
 *    <Link>, so returning to the roster stays a client-side visit.
 *  - All labels run through useTerminology() so the page reads correctly for a
 *    company ("Employee") or college, not just "Student".
 */
export default function Show({ student, costPerMeal, balance, totalMeals, totalDeposits }) {
    // Terminology-aware labels so this detail page reads "Employee"/"Boarder"
    // in a company/college workspace rather than always "Student".
    const { t, tTitle } = useTerminology();
    const { can } = useCan();
    const money = useMoney();
    const canManage = can('students.manage');

    const deposits = student.deposits || [];
    const entries = student.entries || [];

    const owed = Number(balance || 0) < 0;

    return (
        <MealsLayout title={`${tTitle('member', 'Member')} Details`}>
            <Head title={student.name} />

            {/* Header card */}
            <div className="rounded-xl border-slate-200 bg-white p-6 shadow-sm">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-4">
                        <div className="flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-xl bg-indigo-600 text-base font-bold text-white">
                            {initials(student.name)}
                        </div>
                        <div>
                            <h3 className="text-xl font-bold text-slate-900">{student.name}</h3>
                            <div className="mt-1 flex-wrap items-center gap-2 text-xs text-slate-500">
                                {student.roll && (
                                    <span className="rounded bg-slate-100 px-2 py-0.5 font-medium text-slate-600">
                                        {student.roll}
                                    </span>
                                )}
                                {student.department && (
                                    <span className="rounded-full border-slate-200 bg-slate-50 px-2.5 py-0.5 font-medium">
                                        {student.department.name}
                                    </span>
                                )}
                                <span
                                    className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 font-semibold ${(student.status || 'active') === 'active'
                                            ? 'border-emerald-100 bg-emerald-50 text-emerald-700'
                                            : 'border-slate-200 bg-slate-100 text-slate-500'
                                        }`}
                                >
                                    {(student.status || 'active') === 'active' ? 'Active' : 'Inactive'}
                                </span>
                            </div>
                            {student.user && (
                                <p className="mt-1.5 text-xs text-slate-400">
                                    Linked account: <span className="font-medium text-slate-600">{student.user.email}</span>
                                </p>
                            )}
                        </div>
                    </div>

                    <Link
                        href={route('meals.students.index')}
                        className="rounded-lg border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-100"
                    >
                        Back to list
                    </Link>
                </div>
            </div>

            {/* Stats */}
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard label="Total Meals" value={totalMeals ?? 0} hint="Breakfast + lunch + dinner" />
                <StatCard
                    label="Total Deposited"
                    value={money(totalDeposits ?? 0, false)}
                    tone="text-emerald-600"
                />
                <StatCard
                    label={owed ? 'Amount Due' : 'Credit Balance'}
                    value={money(Math.abs(Number(balance || 0)), false)}
                    tone={owed ? 'text-rose-600' : 'text-emerald-600'}
                    hint={
                        costPerMeal
                            ? `Priced at ${money(costPerMeal, false)} per meal`
                            : 'No meal rate set yet'
                    }
                />
                <StatCard
                    label="Meal Rate"
                    value={costPerMeal ? money(costPerMeal, false) : '—'}
                    hint="Current cost per meal"
                />
            </div>

            <div className="grid gap-5 lg:grid-cols-2">
                {/* Deposits */}
                <div className="overflow-hidden rounded-xl border-slate-200 bg-white shadow-sm">
                    <div className="border-b border-slate-100 bg-slate-50 px-5 py-3">
                        <h4 className="text-sm font-bold text-slate-900">Recent Deposits</h4>
                    </div>
                    {deposits.length > 0 ? (
                        <ul className="divide-y divide-slate-100">
                            {deposits.map((deposit) => (
                                <li key={deposit.id} className="flex items-center justify-between px-5 py-3">
                                    <div>
                                        <div className="text-sm font-semibold text-slate-800">
                                            {money(deposit.amount, false)}
                                        </div>
                                        <div className="text-xs text-slate-400">
                                            {deposit.payment_method || 'Cash'}
                                        </div>
                                    </div>
                                    <span className="text-xs text-slate-400">
                                        {new Date(deposit.created_at).toLocaleDateString()}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="px-5 py-8 text-center text-xs italic text-slate-400">
                            No deposits recorded yet.
                        </p>
                    )}
                </div>

                {/* Meal entries */}
                <div className="overflow-hidden rounded-xl border-slate-200 bg-white shadow-sm">
                    <div className="border-b border-slate-100 bg-slate-50 px-5 py-3">
                        <h4 className="text-sm font-bold text-slate-900">Recent Meal Entries</h4>
                    </div>
                    {entries.length > 0 ? (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead>
                                    <tr className="border-b border-slate-100 text-xs font-semibold uppercase tracking-wider text-slate-400">
                                        <th className="px-5 py-2">Date</th>
                                        <th className="px-3 py-2 text-center">B</th>
                                        <th className="px-3 py-2 text-center">L</th>
                                        <th className="px-3 py-2 text-center">D</th>
                                        <th className="px-5 py-2 text-right">Total</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {entries.map((entry) => (
                                        <tr key={entry.id}>
                                            <td className="px-5 py-2.5 text-slate-700">
                                                {new Date(entry.date).toLocaleDateString()}
                                            </td>
                                            <td className="px-3 py-2.5 text-center text-slate-600">{entry.breakfast}</td>
                                            <td className="px-3 py-2.5 text-center text-slate-600">{entry.lunch}</td>
                                            <td className="px-3 py-2.5 text-center text-slate-600">{entry.dinner}</td>
                                            <td className="px-5 py-2.5 text-right font-semibold text-slate-800">
                                                {(entry.breakfast || 0) + (entry.lunch || 0) + (entry.dinner || 0)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <p className="px-5 py-8 text-center text-xs italic text-slate-400">
                            No meal entries recorded yet.
                        </p>
                    )}
                </div>
            </div>
        </MealsLayout>
    );
}
