import React, { useState } from 'react';
import MealsLayout from '@/Layouts/MealsLayout';
import useCan from '@/Utils/can';
import useMoney from '@/Utils/useMoney';
import { useFeedback } from '@/Components/Feedback/FeedbackProvider';
import VendorFormModal from './VendorFormModal';
import PurchaseHistoryModal from './PurchaseHistoryModal';
import { categoryLabel, recurrenceLabel } from './vendorLabels';
import { Head, Link, router, usePage } from '@inertiajs/react';

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

/**
 * Meal module - Vendors & Suppliers.
 *
 * PURPOSE
 * Who the institution buys from - including the institution itself flagged as
 * the primary "hub" supplier. The page lists vendors with their purchased and
 * outstanding totals, and exposes a create/edit modal plus a purchase-history
 * modal. Expenses link back to these vendors for recurring-spend tracking.
 *
 * PROPS (from the Laravel controller)
 *  - vendors: Laravel paginator { data, links, from, to, total }; each row has
 *    category_label, recurrence, contact_person, phone, total_purchased,
 *    outstanding_balance, status and is_institution_hub.
 *  - categories: strings for the category filter + form.
 *  - recurrences: options for the recurrence select.
 *  - filters: { search, category, status } echo of the query.
 *  - totals: { vendors, active, recurring, purchased } headline counts/sums.
 *
 * FLOW
 *  - Create / Edit: delegated to <VendorFormModal>, which owns its own state;
 *    this page only tracks open + the record being edited.
 *  - History: <PurchaseHistoryModal> fetches its own data - the page just
 *    passes the chosen vendor (historyVendor) and lets it close itself.
 *  - Delete / export / filtering: confirm() then router.delete(); export is a
 *    plain anchor; filters re-request via router.get.
 *  - Responsive: a desktop <table> and a mobile card <ul> render the same rows,
 *    toggled with Tailwind's hidden/md:block and md:hidden.
 */
export default function Index({ vendors, categories = [], recurrences = [], filters, totals = {} }) {
    const { can } = useCan();
    const { flash } = usePage().props;
    const money = useMoney();
    const { confirm, alert } = useFeedback();
    const canManage = can('vendors.manage');
    const canExport = can('exports.download');

    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [search, setSearch] = useState(filters?.search || '');

    // Which vendor's purchase history is open (the modal fetches its own data).
    const [historyVendor, setHistoryVendor] = useState(null);

    const rows = vendors?.data || [];

    // The form modal owns its own state; the page only tracks open/which record.
    const openCreate = () => {
        setEditing(null);
        setModalOpen(true);
    };

    const openEdit = (vendor) => {
        setEditing(vendor);
        setModalOpen(true);
    };

    const closeModal = () => {
        setModalOpen(false);
        setEditing(null);
    };

    const remove = async (vendor) => {
        const ok = await confirm({
            title: `Delete "${vendor.name}"?`,
            message: 'This cannot be undone. Vendors with purchase history cannot be deleted - mark them inactive instead.',
            tone: 'danger',
            confirmLabel: 'Delete vendor',
        });
        if (!ok) return;

        router.delete(route('meals.vendors.destroy', vendor.slug), { preserveScroll: true });
    };

    // The history modal fetches its own data; we just pass the selected vendor.
    const openHistory = (vendor) => setHistoryVendor(vendor);

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
                                        <button
                                            type="button"
                                            onClick={() => openHistory(vendor)}
                                            className="font-medium text-slate-600 transition-colors hover:text-slate-900"
                                        >
                                            History
                                        </button>
                                        {canManage && (
                                            <>
                                                <button
                                                    type="button"
                                                    onClick={() => openEdit(vendor)}
                                                    className="ml-4 font-medium text-indigo-600 transition-colors hover:text-indigo-900"
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

                            <div className="flex gap-2 pt-1">
                                <button
                                    type="button"
                                    onClick={() => openHistory(vendor)}
                                    className="flex-1 rounded-lg border-slate-300 py-2 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-50"
                                >
                                    History
                                </button>
                                {canManage && (
                                    <>
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
                                    </>
                                )}
                            </div>
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

            {/* Create / Edit modal (self-contained form component). */}
            <VendorFormModal
                open={modalOpen}
                onClose={closeModal}
                editing={editing}
                categories={categories}
                recurrences={recurrences}
            />

            {/* Purchase History (self-fetching component). */}
            <PurchaseHistoryModal
                open={Boolean(historyVendor)}
                vendor={historyVendor}
                onClose={() => setHistoryVendor(null)}
            />
        </MealsLayout>
    );
}
