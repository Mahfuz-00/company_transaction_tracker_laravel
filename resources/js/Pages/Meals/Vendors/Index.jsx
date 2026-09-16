import React, { useState } from 'react';
import MealsLayout from '@/Layouts/MealsLayout';
import Modal from '@/Components/UI/Modal';
import Field from '@/Components/UI/Field';
import useCan from '@/Utils/can';
import useMoney from '@/Utils/useMoney';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';

const EMPTY_FORM = {
    name: '',
    contact_person: '',
    phone: '',
    email: '',
    address: '',
    category: '',
    recurrence: '',
    lead_time_days: '',
    recurring_amount: '',
    opening_balance: '',
    status: 'active',
    notes: '',
};

const recurrenceLabel = (value) =>
    ({
        daily: 'Daily',
        weekly: 'Weekly',
        fortnightly: 'Every two weeks',
        monthly: 'Monthly',
        quarterly: 'Quarterly',
        on_demand: 'On demand',
    }[value] || value || '');

const categoryLabel = (value) =>
    value
        ? value
            .split('_')
            .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
            .join(' ')
        : '';

function Flash({ success, error }) {
    if (!success && !error) return null;
    const isError = Boolean(error);

    return (
        <div
            role="status"
            className={`flex items-center gap-2 rounded-lg border p-3 text-sm font-medium ${isError
                    ? 'border-rose-200 bg-rose-50 text-rose-700'
                    : 'border-emerald-200 bg-emerald-50 text-emerald-700'
                }`}
        >
            <svg className="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                {isError ? (
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                ) : (
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                )}
            </svg>
            {error || success}
        </div>
    );
}

export default function Index({ vendors, categories = [], recurrences = [], filters, totals = {} }) {
    const { can } = useCan();
    const { flash } = usePage().props;
    const money = useMoney();
    const canManage = can('vendors.manage');
    const canExport = can('exports.download');

    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [search, setSearch] = useState(filters?.search || '');

    const { data, setData, post, put, processing, errors, reset, clearErrors } =
        useForm({ ...EMPTY_FORM });

    const rows = vendors?.data || [];
    const isEditing = Boolean(editing);

    const categoryOptions = [
        { value: '', label: '— No category —' },
        ...categories.map((category) => ({
            value: category,
            label: categoryLabel(category),
        })),
    ];

    const openCreate = () => {
        clearErrors();
        reset();
        setData({ ...EMPTY_FORM });
        setEditing(null);
        setModalOpen(true);
    };

    const openEdit = (vendor) => {
        clearErrors();
        setEditing(vendor);
        setData({
            name: vendor.name || '',
            contact_person: vendor.contact_person || '',
            phone: vendor.phone || '',
            email: vendor.email || '',
            address: vendor.address || '',
            category: vendor.category || '',
            recurrence: vendor.recurrence || '',
            lead_time_days: vendor.lead_time_days ?? '',
            recurring_amount: vendor.recurring_amount ?? '',
            opening_balance: vendor.opening_balance ?? '',
            status: vendor.status || 'active',
            notes: vendor.notes || '',
        });
        setModalOpen(true);
    };

    const closeModal = () => {
        setModalOpen(false);
        setEditing(null);
        reset();
    };

    const submit = (event) => {
        event.preventDefault();

        if (isEditing) {
            put(route('meals.vendors.update', editing.slug), {
                preserveScroll: true,
                onSuccess: () => closeModal(),
            });
        } else {
            post(route('meals.vendors.store'), {
                preserveScroll: true,
                onSuccess: () => closeModal(),
            });
        }
    };

    const remove = (vendor) => {
        if (!confirm(`Delete vendor "${vendor.name}"? This cannot be undone.`)) return;
        router.delete(route('meals.vendors.destroy', vendor.slug), { preserveScroll: true });
    };

    const applyFilters = (next) => {
        router.get(
            route('meals.vendors.index'),
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true, replace: true }
        );
    };

    const hasFilters =
        Boolean(filters?.search) || Boolean(filters?.category) || Boolean(filters?.status);

    return (
        <MealsLayout
            title="Vendors & Suppliers"
            description="Who the institution buys from - including the institution itself as the primary hub - and how much has been spent with each."
            actions={
                <div className="flex flex-wrap items-center gap-2">
                    {canExport && (
                        <div className="flex overflow-hidden rounded-lg border-slate-300">
                            <a href={`${route('meals.vendors.export')}?format=excel`} className="border-r border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50">Excel</a>
                            <a href={`${route('meals.vendors.export')}?format=pdf`} target="_blank" rel="noreferrer" className="bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50">PDF</a>
                        </div>
                    )}
                    {canManage && (
                        <button
                            type="button"
                            onClick={openCreate}
                            className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition-all hover:bg-indigo-700 active:bg-indigo-800"
                        >
                            <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                            </svg>
                            Add Vendor
                        </button>
                    )}
                </div>
            }
        >
            <Head title="Vendors" />

            <Flash success={flash?.success} error={flash?.error} />

            {/* Totals */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div className="rounded-xl border-slate-200 bg-white p-4 shadow-sm">
                    <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        Registered Vendors
                    </div>
                    <div className="mt-1 text-xl font-bold text-slate-900">
                        {totals.vendors ?? 0}
                        <span className="ml-2 text-xs font-medium text-emerald-600">
                            {totals.active ?? 0} active
                        </span>
                        <span className="ml-2 text-xs font-medium text-sky-600">
                            {totals.recurring ?? 0} recurring
                        </span>
                    </div>
                </div>
                <div className="rounded-xl border-slate-200 bg-white p-4 shadow-sm sm:col-span-2">
                    <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        Total Purchased From Vendors
                    </div>
                    <div className="mt-1 text-xl font-bold text-rose-600">
                        {money(totals.purchased ?? 0, false)}
                    </div>
                </div>
            </div>

            <div className="overflow-hidden rounded-xl border-slate-200 bg-white shadow-sm">
                {/* Filters */}
                <div className="flex flex-col gap-3 border-b border-slate-100 px-6 py-4 sm:flex-row sm:items-center">
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            applyFilters({ search });
                        }}
                        className="relative max-w-sm flex-1"
                    >
                        <svg
                            className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        <input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search name, contact or phone..."
                            className="w-full rounded-lg border-slate-300 py-2 pl-9 pr-3 text-sm outline-none transition-all focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                        />
                    </form>

                    <select
                        value={filters?.category || ''}
                        onChange={(event) => applyFilters({ category: event.target.value })}
                        className="rounded-lg border-slate-300 text-sm text-slate-900 focus:border-indigo-500 focus:ring-indigo-500"
                    >
                        <option value="">All categories</option>
                        {categories.map((category) => (
                            <option key={category} value={category}>
                                {categoryLabel(category)}
                            </option>
                        ))}
                    </select>

                    <select
                        value={filters?.status || ''}
                        onChange={(event) => applyFilters({ status: event.target.value })}
                        className="rounded-lg border-slate-300 text-sm text-slate-900 focus:border-indigo-500 focus:ring-indigo-500"
                    >
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>

                    {hasFilters && (
                        <button
                            type="button"
                            onClick={() => {
                                setSearch('');
                                router.get(route('meals.vendors.index'), {}, { replace: true });
                            }}
                            className="text-xs font-semibold text-indigo-600 transition-colors hover:text-indigo-800"
                        >
                            Clear filters
                        </button>
                    )}
                </div>

                {/* Desktop table (hidden on small screens) */}
                <div className="hidden overflow-x-auto md:block">
                    <table className="w-full min-w-180 border-collapse text-left">
                        <thead>
                            <tr className="border-b border-slate-100 bg-slate-50 text-xs font-semibold uppercase tracking-wider text-slate-400">
                                <th className="px-4 py-3 sm:px-6">Vendor</th>
                                <th className="px-4 py-3 sm:px-6">Category</th>
                                <th className="px-4 py-3 sm:px-6">Recurrence</th>
                                <th className="px-4 py-3 sm:px-6">Contact</th>
                                <th className="px-4 py-3 text-right sm:px-6">Purchased</th>
                                <th className="px-4 py-3 text-right sm:px-6">Outstanding</th>
                                <th className="px-4 py-3 sm:px-6">Status</th>
                                <th className="px-4 py-3 text-right sm:px-6">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 text-sm">
                            {rows.map((vendor) => (
                                <tr key={vendor.id} className={`transition-colors hover:bg-slate-50/60 ${vendor.is_institution_hub ? 'bg-indigo-50/40' : ''}`}>
                                    <td className="px-4 py-4 sm:px-6">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-semibold text-slate-900">{vendor.name}</span>
                                            {vendor.is_institution_hub && (
                                                <span className="inline-flex items-center rounded-full border-indigo-200 bg-indigo-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-indigo-700">
                                                    Hub
                                                </span>
                                            )}
                                        </div>
                                        {vendor.address && (
                                            <div className="truncate text-xs text-slate-400">{vendor.address}</div>
                                        )}
                                    </td>
                                    <td className="px-4 py-4 sm:px-6">
                                        {vendor.category ? (
                                            <span className="inline-flex rounded-full border-slate-200 bg-slate-50 px-2.5 py-0.5 text-xs font-medium text-slate-700">
                                                {vendor.category_label}
                                            </span>
                                        ) : (
                                            <span className="text-slate-300">—</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-4 sm:px-6">
                                        {vendor.recurrence ? (
                                            <span className="inline-flex items-center rounded-full border-sky-100 bg-sky-50 px-2.5 py-0.5 text-xs font-semibold text-sky-700">
                                                {recurrenceLabel(vendor.recurrence)}
                                            </span>
                                        ) : (
                                            <span className="text-xs italic text-slate-400">One-off</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-4 text-xs text-slate-600 sm:px-6">
                                        <div className="font-medium">{vendor.contact_person || '—'}</div>
                                        {vendor.phone && <div className="text-slate-400">{vendor.phone}</div>}
                                    </td>
                                    <td className="px-4 py-4 text-right font-semibold text-slate-800 sm:px-6">
                                        {money(vendor.total_purchased ?? 0, false)}
                                    </td>
                                    <td className="px-4 py-4 text-right sm:px-6">
                                        {Number(vendor.outstanding_balance) > 0 ? (
                                            <span className="font-bold text-amber-600">
                                                {money(vendor.outstanding_balance, false)}
                                            </span>
                                        ) : (
                                            <span className="text-slate-300">—</span>
                                        )}
                                    </td>
                                    <td className="px-6 py-4">
                                        <span
                                            className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-semibold ${vendor.status === 'active'
                                                    ? 'border-emerald-100 bg-emerald-50 text-emerald-700'
                                                    : 'border-slate-200 bg-slate-100 text-slate-500'
                                                }`}
                                        >
                                            <span
                                                className={`h-1.5 w-1.5 rounded-full ${vendor.status === 'active' ? 'bg-emerald-500' : 'bg-slate-400'
                                                    }`}
                                            />
                                            {vendor.status === 'active' ? 'Active' : 'Inactive'}
                                        </span>
                                    </td>
                                    <td className="whitespace-nowrap px-6 py-4 text-right">
                                        {canManage && (
                                            <>
                                                <button
                                                    type="button"
                                                    onClick={() => openEdit(vendor)}
                                                    className="font-medium text-indigo-600 transition-colors hover:text-indigo-900"
                                                >
                                                    Edit
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => remove(vendor)}
                                                    className="ml-4 font-medium text-rose-500 transition-colors hover:text-rose-700"
                                                >
                                                    Delete
                                                </button>
                                            </>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {/* Mobile cards */}
                <ul className="divide-y divide-slate-100 md:hidden">
                    {rows.map((vendor) => (
                        <li key={vendor.id} className="space-y-3 p-4">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <div className="truncate font-semibold text-slate-900">{vendor.name}</div>
                                    <div className="text-xs text-slate-400">
                                        {vendor.category_label || 'No category'}
                                    </div>
                                </div>
                                <span
                                    className={`flex-shrink-0 rounded-full border px-2.5 py-0.5 text-[10px] font-semibold ${vendor.status === 'active'
                                            ? 'border-emerald-100 bg-emerald-50 text-emerald-700'
                                            : 'border-slate-200 bg-slate-100 text-slate-500'
                                        }`}
                                >
                                    {vendor.status === 'active' ? 'Active' : 'Inactive'}
                                </span>
                            </div>

                            <div className="grid grid-cols-2 gap-3 text-xs">
                                <div>
                                    <div className="font-semibold uppercase tracking-wide text-slate-400">Purchased</div>
                                    <div className="font-bold text-slate-800">
                                        {money(vendor.total_purchased ?? 0, false)}
                                    </div>
                                </div>
                                <div>
                                    <div className="font-semibold uppercase tracking-wide text-slate-400">Outstanding</div>
                                    <div
                                        className={`font-bold ${Number(vendor.outstanding_balance) > 0
                                                ? 'text-amber-600'
                                                : 'text-slate-400'
                                            }`}
                                    >
                                        {Number(vendor.outstanding_balance) > 0
                                            ? money(vendor.outstanding_balance, false)
                                            : '—'}
                                    </div>
                                </div>
                            </div>

                            {(vendor.contact_person || vendor.phone) && (
                                <div className="text-xs text-slate-500">
                                    {vendor.contact_person}
                                    {vendor.phone && <span className="text-slate-400"> · {vendor.phone}</span>}
                                </div>
                            )}

                            {canManage && (
                                <div className="flex gap-2 pt-1">
                                    <button
                                        type="button"
                                        onClick={() => openEdit(vendor)}
                                        className="flex-1 rounded-lg border-slate-300 py-2 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-50"
                                    >
                                        Edit
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => remove(vendor)}
                                        className="flex-1 rounded-lg border-rose-200 py-2 text-xs font-semibold text-rose-600 transition-colors hover:bg-rose-50"
                                    >
                                        Delete
                                    </button>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>

                {rows.length === 0 && (
                    <div className="py-14 text-center">
                        <p className="text-sm font-semibold text-slate-600">
                            {hasFilters ? 'No vendors match these filters.' : 'No vendors yet.'}
                        </p>
                        <p className="mt-1 text-xs text-slate-400">
                            Add suppliers so expenses can be attributed to them.
                        </p>
                    </div>
                )}

                {/* Pagination */}
                {vendors?.links?.length > 3 && (
                    <div className="flex flex-col items-center justify-between gap-3 border-t border-slate-100 px-6 py-4 sm:flex-row">
                        <p className="text-xs text-slate-500">
                            Showing <strong>{vendors.from}</strong>–<strong>{vendors.to}</strong> of{' '}
                            <strong>{vendors.total}</strong>
                        </p>
                        <div className="flex flex-wrap gap-1">
                            {vendors.links.map((link, index) => (
                                <Link
                                    key={index}
                                    href={link.url || '#'}
                                    preserveScroll
                                    className={`rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors ${link.active
                                            ? 'border-indigo-600 bg-indigo-600 text-white'
                                            : link.url
                                                ? 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
                                                : 'pointer-events-none border-slate-100 bg-white text-slate-300'
                                        }`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    </div>
                )}
            </div>

            {/* Create / Edit modal */}
            <Modal
                open={modalOpen}
                onClose={closeModal}
                title={isEditing ? `Edit ${editing?.name}` : 'Add Vendor'}
                description={
                    isEditing
                        ? 'Update supplier details and status.'
                        : 'Register a supplier the institution buys from.'
                }
                footer={
                    <>
                        <button
                            type="button"
                            onClick={closeModal}
                            className="rounded-lg border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-100"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            form="vendor-form"
                            disabled={processing}
                            className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white shadow-sm transition-all hover:bg-indigo-700 active:bg-indigo-800 disabled:opacity-50"
                        >
                            {processing && (
                                <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                    <circle cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" className="opacity-25" />
                                    <path d="M4 12a8 8 0 018-8" stroke="currentColor" strokeWidth="4" strokeLinecap="round" className="opacity-90" />
                                </svg>
                            )}
                            {processing ? 'Saving...' : isEditing ? 'Save Changes' : 'Add Vendor'}
                        </button>
                    </>
                }
            >
                <form id="vendor-form" onSubmit={submit} className="space-y-4">
                    <Field
                        label="Vendor Name"
                        name="name"
                        required
                        value={data.name}
                        error={errors.name}
                        placeholder="e.g. Rahim General Store"
                        onChange={(event) => setData('name', event.target.value)}
                    />

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Category"
                            name="category"
                            type="select"
                            value={data.category}
                            error={errors.category}
                            options={categoryOptions}
                            onChange={(event) => setData('category', event.target.value)}
                        />
                        <Field
                            label="Status"
                            name="status"
                            type="select"
                            required
                            value={data.status}
                            error={errors.status}
                            options={[
                                { value: 'active', label: 'Active' },
                                { value: 'inactive', label: 'Inactive' },
                            ]}
                            onChange={(event) => setData('status', event.target.value)}
                        />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Contact Person"
                            name="contact_person"
                            value={data.contact_person}
                            error={errors.contact_person}
                            placeholder="e.g. Rahim Uddin"
                            onChange={(event) => setData('contact_person', event.target.value)}
                        />
                        <Field
                            label="Phone"
                            name="phone"
                            value={data.phone}
                            error={errors.phone}
                            placeholder="+8801XXXXXXXXX"
                            onChange={(event) => setData('phone', event.target.value)}
                        />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Email"
                            name="email"
                            type="email"
                            value={data.email}
                            error={errors.email}
                            placeholder="vendor@example.com"
                            onChange={(event) => setData('email', event.target.value)}
                        />
                        <Field
                            label="Opening Balance"
                            name="opening_balance"
                            type="number"
                            step="0.01"
                            min="0"
                            value={data.opening_balance}
                            error={errors.opening_balance}
                            placeholder="0.00"
                            hint="Amount already owed at setup."
                            onChange={(event) => setData('opening_balance', event.target.value)}
                        />
                    </div>

                    {/* Recurring purchase settings */}
                    <div className="rounded-lg border-slate-200 bg-slate-50 p-4">
                        <p className="mb-3 text-xs font-bold uppercase tracking-wider text-slate-500">
                            Recurring Purchases
                        </p>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <Field
                                label="Buys From"
                                name="recurrence"
                                type="select"
                                value={data.recurrence}
                                error={errors.recurrence}
                                options={[{ value: '', label: '— One-off —' }, ...recurrences]}
                                onChange={(event) => setData('recurrence', event.target.value)}
                            />
                            <Field
                                label="Lead Time (days)"
                                name="lead_time_days"
                                type="number"
                                min="0"
                                value={data.lead_time_days}
                                error={errors.lead_time_days}
                                placeholder="e.g. 2"
                                onChange={(event) => setData('lead_time_days', event.target.value)}
                            />
                            <Field
                                label="Typical Order Value"
                                name="recurring_amount"
                                type="number"
                                step="0.01"
                                min="0"
                                value={data.recurring_amount}
                                error={errors.recurring_amount}
                                placeholder="e.g. 8000"
                                onChange={(event) => setData('recurring_amount', event.target.value)}
                            />
                        </div>
                    </div>

                    <Field
                        label="Address"
                        name="address"
                        value={data.address}
                        error={errors.address}
                        placeholder="Market, city"
                        onChange={(event) => setData('address', event.target.value)}
                    />

                    <Field
                        label="Notes"
                        name="notes"
                        type="textarea"
                        value={data.notes}
                        error={errors.notes}
                        placeholder="Payment terms, delivery days, etc."
                    />
                </form>
            </Modal>
        </MealsLayout>
    );
}
