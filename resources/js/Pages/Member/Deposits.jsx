import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import useMoney from '@/Utils/useMoney';
import useTerminology from '@/Utils/useTerminology';
import { Head, Link } from '@inertiajs/react';

/**
 * A member's own deposit history.
 *
 * Strictly personal: every row belongs to the signed-in member. Reversed
 * deposits are shown struck-through so the member sees the full audit trail
 * without the amount counting toward their balance.
 */
export default function Deposits({ hasMemberRecord = true, member = {}, deposits, totalDeposited = 0 }) {
    const money = useMoney();
    const { t } = useTerminology();

    if (!hasMemberRecord) {
        return (
            <AuthenticatedLayout header={<h2 className="text-xl font-bold text-slate-900">My {t('deposits', 'Deposits')}</h2>}>
                <Head title="My Deposits" />
                <div className="rounded-2xl border-slate-200 bg-white p-10 text-center shadow-sm">
                    <h3 className="text-base font-bold text-slate-800">No member record linked</h3>
                    <p className="mx-auto mt-2 max-w-md text-sm text-slate-500">
                        Your login is not yet linked to a member record. Ask your manager to link it.
                    </p>
                </div>
            </AuthenticatedLayout>
        );
    }

    const rows = deposits?.data || [];

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <h2 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">My {t('deposits', 'Deposits')}</h2>
                    <p className="mt-0.5 text-xs font-medium text-slate-500">
                        Every payment recorded against your account
                    </p>
                </div>
            }
        >
            <Head title="My Deposits" />

            <div className="space-y-5">
                {/* Lifetime total */}
                <div className="rounded-2xl border-emerald-200 bg-emerald-50/60 p-6 shadow-sm">
                    <p className="text-[11px] font-bold uppercase tracking-wider text-slate-500">Total Deposited</p>
                    <p className="mt-1 text-4xl font-extrabold tracking-tight text-emerald-600">
                        {money(totalDeposited, false)}
                    </p>
                    <p className="mt-1 text-xs font-medium text-slate-500">
                        Excludes any reversed entries below.
                    </p>
                </div>

                <div className="overflow-hidden rounded-2xl border-slate-200 bg-white shadow-sm">
                    <div className="border-b border-slate-100 px-6 py-4">
                        <h3 className="text-base font-bold text-slate-900">Deposit History</h3>
                        <p className="text-xs text-slate-500">Most recent first</p>
                    </div>

                    {rows.length > 0 ? (
                        <ul className="divide-y divide-slate-100">
                            {rows.map((dep) => (
                                <li key={dep.id} className={`flex items-center justify-between gap-3 px-6 py-4 ${dep.reversed ? 'opacity-60' : ''}`}>
                                    <div className="min-w-0">
                                        <div className={`text-base font-bold ${dep.reversed ? 'text-slate-400 line-through' : 'text-slate-800'}`}>
                                            {money(dep.amount, false)}
                                        </div>
                                        <div className="truncate text-xs text-slate-400">
                                            {dep.method || 'Cash'}
                                            {dep.kind && dep.kind !== 'personal' ? ` · ${dep.kind}` : ''}
                                            {dep.notes ? ` · ${dep.notes}` : ''}
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
                        <p className="px-6 py-14 text-center text-sm italic text-slate-400">
                            No deposits recorded yet.
                        </p>
                    )}

                    {deposits?.links?.length > 3 && (
                        <div className="flex items-center justify-between gap-4 border-t border-slate-100 px-6 py-4">
                            <p className="text-xs text-slate-500">
                                Showing <strong>{deposits.from}</strong>–<strong>{deposits.to}</strong> of <strong>{deposits.total}</strong>
                            </p>
                            <div className="flex flex-wrap gap-1">
                                {deposits.links.map((link, index) => (
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
