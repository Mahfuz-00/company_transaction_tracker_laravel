import React, { useState } from 'react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import useCan from '@/Utils/can';
import useMoney from '@/Utils/useMoney';
import { Head, router, usePage } from '@inertiajs/react';

/**
 * Software Super Admin only: every institution on the platform, each with its
 * administrators and headline figures for the current month.
 */
export default function InstitutionRegistry({ institutions, filters, totals }) {
    const { can, isSuperAdmin } = useCan();
    const { flash } = usePage().props;
    const money = useMoney();
    const canManage = can('institutions.manage');

    const [search, setSearch] = useState(filters?.search || '');
    const [expanded, setExpanded] = useState(null);

    const submitSearch = (e) => {
        e.preventDefault();
        router.get(route('settings.institutions.index'), { search }, {
            preserveState: true, preserveScroll: true, replace: true,
        });
    };

    const toggle = (institution) => {
        router.patch(route('settings.institutions.toggle', institution.id), {}, { preserveScroll: true });
    };

    return (
        <SettingsLayout title="Settings">
            <Head title="Institution Registry" />

            <div className="space-y-5">
                <div>
                    <h3 className="text-lg font-bold text-slate-900">Institution Registry</h3>
                    <p className="mt-0.5 text-sm text-slate-500">
                        Every workspace on the platform, with its administrators and current-month figures.
                    </p>
                </div>

                {flash?.success && (
                    <div role="status" className="rounded-lg border-emerald-200 bg-emerald-50 p-3 text-sm font-medium text-emerald-700">
                        {flash.success}
                    </div>
                )}

                {/* Platform totals */}
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    {[
                        { label: 'Institutions', value: totals?.institutions ?? 0, tone: 'text-slate-900' },
                        { label: 'Active', value: totals?.active ?? 0, tone: 'text-emerald-600' },
                        { label: 'Members', value: totals?.members ?? 0, tone: 'text-[var(--accent)]' },
                        { label: 'Administrators', value: totals?.admins ?? 0, tone: 'text-sky-600' },
                    ].map((card) => (
                        <div key={card.label} className="rounded-xl border-slate-200 bg-white p-4 shadow-sm">
                            <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                                {card.label}
                            </div>
                            <div className={`mt-1 text-2xl font-bold ${card.tone}`}>{card.value}</div>
                        </div>
                    ))}
                </div>

                <form onSubmit={submitSearch} className="max-w-sm">
                    <input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search institutions..."
                        className="w-full rounded-lg border-slate-300 px-3 py-2 text-sm outline-none transition-all focus:border-[var(--accent)] focus:ring-2 focus:ring-[var(--accent-ring)]"
                    />
                </form>

                {/* Institution cards */}
                <div className="space-y-4">
                    {institutions.length > 0 ? institutions.map((inst) => (
                        <div key={inst.id} className="overflow-hidden rounded-xl border-slate-200 bg-white shadow-sm animate-rise">
                            <div className="flex flex-col gap-4 p-5 sm:flex-row sm:items-start sm:justify-between">
                                <div className="flex min-w-0 gap-4">
                                    {/* Accent swatch / logo */}
                                    {inst.logo_url ? (
                                        <img src={inst.logo_url} alt={inst.name} className="h-12 w-12 flex-shrink-0 rounded-lg object-contain" />
                                    ) : (
                                        <div
                                            className="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-lg text-sm font-bold text-white"
                                            style={{ backgroundColor: inst.accent_hex }}
                                        >
                                            {inst.name.slice(0, 2).toUpperCase()}
                                        </div>
                                    )}

                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <h4 className="text-base font-bold text-slate-900">{inst.name}</h4>
                                            <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-bold uppercase ${inst.is_active
                                                ? 'border-emerald-100 bg-emerald-50 text-emerald-700'
                                                : 'border-slate-200 bg-slate-100 text-slate-500'}`}>
                                                {inst.is_active ? 'Active' : 'Inactive'}
                                            </span>
                                        </div>
                                        <p className="mt-0.5 text-xs text-slate-500">
                                            {inst.type_label}
                                            {inst.subtitle ? ` · ${inst.subtitle}` : ''}
                                        </p>
                                        <div className="mt-2 flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                                            <span><strong className="font-semibold text-slate-700">{inst.members_count}</strong> members</span>
                                            <span><strong className="font-semibold text-slate-700">{inst.vendors_count}</strong> vendors</span>
                                            <span><strong className="font-semibold text-slate-700">{inst.admin_count}</strong> admins</span>
                                            <span>Currency: <strong className="font-semibold text-slate-700">{inst.currency_code || 'default'}</strong></span>
                                        </div>
                                    </div>
                                </div>

                                <div className="flex flex-shrink-0 items-center gap-2">
                                    <button
                                        type="button"
                                        onClick={() => setExpanded(expanded === inst.id ? null : inst.id)}
                                        className="rounded-lg border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50"
                                    >
                                        {expanded === inst.id ? 'Hide admins' : `View ${inst.admin_count} admin(s)`}
                                    </button>
                                    {canManage && (
                                        <button
                                            type="button"
                                            onClick={() => toggle(inst)}
                                            className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors ${inst.is_active
                                                ? 'bg-rose-50 text-rose-600 hover:bg-rose-100'
                                                : 'bg-emerald-50 text-emerald-600 hover:bg-emerald-100'}`}
                                        >
                                            {inst.is_active ? 'Deactivate' : 'Activate'}
                                        </button>
                                    )}
                                </div>
                            </div>

                            {/* Month figures */}
                            <div className="grid grid-cols-2 gap-px border-t border-slate-100 bg-slate-100 sm:grid-cols-3 lg:grid-cols-6">
                                {[
                                    { label: 'Meals', value: inst.month_summary.meals },
                                    { label: 'Expenses', value: money(inst.month_summary.expenses) },
                                    { label: 'Subsidies', value: money(inst.month_summary.subsidies) },
                                    { label: 'Deposits', value: money(inst.month_summary.deposits) },
                                    { label: 'Per-meal rate', value: money(inst.month_summary.per_meal_rate, false) },
                                    { label: 'Pool balance', value: money(inst.month_summary.pool_balance) },
                                ].map((cell) => (
                                    <div key={cell.label} className="bg-white px-4 py-3">
                                        <div className="text-[10px] font-semibold uppercase tracking-wider text-slate-400">
                                            {cell.label}
                                        </div>
                                        <div className="mt-0.5 text-sm font-bold text-slate-800">{cell.value}</div>
                                    </div>
                                ))}
                            </div>

                            {/* Admins */}
                            {expanded === inst.id && (
                                <div className="border-t border-slate-100 bg-slate-50/50 p-5 animate-in">
                                    <h5 className="mb-3 text-xs font-bold uppercase tracking-wider text-slate-500">
                                        Administrators
                                    </h5>
                                    {inst.admins.length > 0 ? (
                                        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                            {inst.admins.map((admin) => (
                                                <div key={admin.id} className="flex items-center gap-3 rounded-lg border-slate-200 bg-white p-3">
                                                    {admin.avatar_url ? (
                                                        <img src={admin.avatar_url} alt={admin.name} className="h-9 w-9 flex-shrink-0 rounded-lg object-cover" />
                                                    ) : (
                                                        <span className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-[var(--accent-soft)] text-xs font-bold text-[var(--accent)]">
                                                            {admin.name.slice(0, 2).toUpperCase()}
                                                        </span>
                                                    )}
                                                    <div className="min-w-0">
                                                        <div className="truncate text-sm font-semibold text-slate-800">{admin.name}</div>
                                                        <div className="truncate text-xs text-slate-400">{admin.email}</div>
                                                        <div className="mt-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-400">
                                                            {admin.role}{admin.designation ? ` · ${admin.designation}` : ''}
                                                        </div>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="text-sm text-slate-500">
                                            No administrators are assigned to this institution yet.
                                        </p>
                                    )}
                                </div>
                            )}
                        </div>
                    )) : (
                        <div className="rounded-xl border-slate-200 bg-white py-14 text-center shadow-sm">
                            <p className="text-sm font-semibold text-slate-600">No institutions found.</p>
                            <p className="mt-1 text-xs text-slate-400">Institutions appear here as they are created.</p>
                        </div>
                    )}
                </div>
            </div>
        </SettingsLayout>
    );
}
