import React, { useState } from 'react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import Modal from '@/Components/UI/Modal';
import Field from '@/Components/UI/Field';
import useCan from '@/Utils/can';
import useMoney from '@/Utils/useMoney';
import { useFeedback } from '@/Components/Feedback/FeedbackProvider';
import { Head, router, useForm } from '@inertiajs/react';

/**
 * Software Super Admin only: every institution on the platform.
 *
 * Each card is the single control surface for a workspace - headline figures,
 * its administrators, an active/inactive toggle, and a prominent "Access
 * Dashboard" button that switches the SSA into that institution. New
 * institutions are created together with their first admin account.
 */
export default function InstitutionRegistry({ institutions, filters, totals, types = [] }) {
    const { can } = useCan();
    const money = useMoney();
    const { confirm } = useFeedback();
    const canManage = can('institutions.manage');

    const [search, setSearch] = useState(filters?.search || '');
    const [expanded, setExpanded] = useState(null);
    const [createOpen, setCreateOpen] = useState(false);

    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        name: '',
        type: types[0]?.value || 'general_mess',
        subtitle: '',
        contact_email: '',
        contact_phone: '',
        address: '',
        currency_code: '',
        timezone: '',
        admin_name: '',
        admin_email: '',
        admin_password: '',
    });

    const submitSearch = (e) => {
        e.preventDefault();
        router.get(route('settings.institutions.index'), { search }, {
            preserveState: true, preserveScroll: true, replace: true,
        });
    };

    const toggle = async (institution) => {
        const deactivating = institution.is_active;
        const ok = await confirm({
            title: deactivating ? `Deactivate "${institution.name}"?` : `Activate "${institution.name}"?`,
            message: deactivating
                ? 'Members of this workspace will not be able to use it until it is reactivated.'
                : 'This workspace will become available to its members again.',
            tone: deactivating ? 'warning' : 'info',
            confirmLabel: deactivating ? 'Deactivate' : 'Activate',
        });
        if (!ok) return;

        router.patch(route('settings.institutions.toggle', institution.id), {}, { preserveScroll: true });
    };

    // Jump directly into this institution's workspace dashboard.
    const switchTo = (institution) => {
        router.patch(route('settings.institutions.switch', institution.id), {}, { preserveScroll: true });
    };

    const openCreate = () => {
        clearErrors();
        reset();
        setCreateOpen(true);
    };

    const submitCreate = (e) => {
        e.preventDefault();
        post(route('settings.institutions.store'), {
            preserveScroll: true,
            onSuccess: () => setCreateOpen(false),
        });
    };

    const typeOptions = types.map((t) => ({ value: t.value, label: t.label }));

    return (
        <SettingsLayout title="Settings">
            <Head title="Institution Registry" />

            <div className="space-y-5">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h3 className="text-lg font-bold text-slate-900">Institution Registry</h3>
                        <p className="mt-0.5 text-sm text-slate-500">
                            Every workspace on the platform, with its administrators and current-month figures.
                        </p>
                    </div>
                    {canManage && (
                        <button
                            type="button"
                            onClick={openCreate}
                            className="inline-flex items-center gap-2 self-start rounded-lg bg-[var(--accent)] px-4 py-2 text-sm font-semibold text-white shadow-sm transition-opacity hover:opacity-90"
                        >
                            <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                            </svg>
                            New Institution
                        </button>
                    )}
                </div>

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

                                <div className="flex flex-shrink-0 flex-wrap items-center gap-2">
                                    {/* Primary action: jump into this institution's dashboard. */}
                                    {canManage && (
                                        <button
                                            type="button"
                                            onClick={() => switchTo(inst)}
                                            title={`Open the ${inst.name} dashboard`}
                                            className="inline-flex items-center gap-1.5 rounded-lg bg-[var(--accent)] px-3.5 py-1.5 text-xs font-bold text-white shadow-sm transition-opacity hover:opacity-90"
                                        >
                                            <svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                                            </svg>
                                            Access Dashboard
                                        </button>
                                    )}
                                    <button
                                        type="button"
                                        onClick={() => setExpanded(expanded === inst.id ? null : inst.id)}
                                        className="rounded-lg border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50"
                                    >
                                        {expanded === inst.id ? 'Hide admins' : `Admins (${inst.admin_count})`}
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

            {/* Create Institution (with its first admin) */}
            <Modal
                open={createOpen}
                onClose={() => setCreateOpen(false)}
                title="New Institution"
                description="Creates the workspace and its first Institution Admin account together."
                maxWidth="max-w-2xl"
                footer={
                    <>
                        <button
                            type="button"
                            onClick={() => setCreateOpen(false)}
                            className="rounded-lg border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-100"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            form="institution-create-form"
                            disabled={processing}
                            className="inline-flex items-center gap-2 rounded-lg bg-[var(--accent)] px-5 py-2 text-sm font-medium text-white shadow-sm transition-opacity hover:opacity-90 disabled:opacity-50"
                        >
                            {processing ? 'Creating...' : 'Create Institution'}
                        </button>
                    </>
                }
            >
                <form id="institution-create-form" onSubmit={submitCreate} className="space-y-5">
                    <div className="space-y-4">
                        <p className="text-xs font-bold uppercase tracking-wider text-[var(--accent)]">Institution</p>
                        <Field
                            label="Institution Name"
                            name="name"
                            required
                            value={data.name}
                            error={errors.name}
                            placeholder="e.g. North Campus Hall"
                            onChange={(e) => setData('name', e.target.value)}
                        />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Institution Type"
                                name="type"
                                type="select"
                                required
                                value={data.type}
                                error={errors.type}
                                options={typeOptions}
                                onChange={(e) => setData('type', e.target.value)}
                            />
                            <Field
                                label="Subtitle"
                                name="subtitle"
                                value={data.subtitle}
                                error={errors.subtitle}
                                placeholder="e.g. Shared meals, tracked"
                                onChange={(e) => setData('subtitle', e.target.value)}
                            />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Contact Email"
                                name="contact_email"
                                type="email"
                                value={data.contact_email}
                                error={errors.contact_email}
                                placeholder="admin@example.com"
                                onChange={(e) => setData('contact_email', e.target.value)}
                            />
                            <Field
                                label="Contact Phone"
                                name="contact_phone"
                                value={data.contact_phone}
                                error={errors.contact_phone}
                                placeholder="+8801XXXXXXXXX"
                                onChange={(e) => setData('contact_phone', e.target.value)}
                            />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Currency Code"
                                name="currency_code"
                                value={data.currency_code}
                                error={errors.currency_code}
                                placeholder="e.g. BDT"
                                hint="Refine the symbol and format later in the Currency Manager."
                                onChange={(e) => setData('currency_code', e.target.value)}
                            />
                            <Field
                                label="Timezone"
                                name="timezone"
                                value={data.timezone}
                                error={errors.timezone}
                                placeholder="Asia/Dhaka"
                                onChange={(e) => setData('timezone', e.target.value)}
                            />
                        </div>
                        <Field
                            label="Address"
                            name="address"
                            value={data.address}
                            error={errors.address}
                            placeholder="Street, city"
                            onChange={(e) => setData('address', e.target.value)}
                        />
                    </div>

                    <div className="space-y-4 rounded-lg border-slate-200 bg-slate-50 p-4">
                        <p className="text-xs font-bold uppercase tracking-wider text-[var(--accent)]">
                            Initial Institution Admin
                        </p>
                        <Field
                            label="Admin Full Name"
                            name="admin_name"
                            required
                            value={data.admin_name}
                            error={errors.admin_name}
                            placeholder="e.g. Rahim Uddin"
                            onChange={(e) => setData('admin_name', e.target.value)}
                        />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Admin Email"
                                name="admin_email"
                                type="email"
                                required
                                value={data.admin_email}
                                error={errors.admin_email}
                                placeholder="admin@institution.com"
                                onChange={(e) => setData('admin_email', e.target.value)}
                            />
                            <Field
                                label="Temporary Password"
                                name="admin_password"
                                type="password"
                                required
                                value={data.admin_password}
                                error={errors.admin_password}
                                placeholder="At least 8 characters"
                                hint="The admin can change this after signing in."
                                onChange={(e) => setData('admin_password', e.target.value)}
                            />
                        </div>
                    </div>
                </form>
            </Modal>
        </SettingsLayout>
    );
}
