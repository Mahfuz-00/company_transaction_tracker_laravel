import React, { useMemo, useState } from 'react';
import MealsLayout from '@/Layouts/MealsLayout';
import Modal from '@/Components/UI/Modal';
import Field from '@/Components/UI/Field';
import useCan from '@/Utils/can';
import useMoney from '@/Utils/useMoney';
import { useFeedback } from '@/Components/Feedback/FeedbackProvider';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';

const EMPTY_FORM = {
    amount: '',
    description: '',
    category: 'Groceries',
    // Vendors are linked directly, so recurring shopping is attributed to the
    // right supplier without retyping the name each time.
    vendor_id: '',
    payment_status: 'paid',
    notes: '',
};

const PAYMENT_STATUSES = [
    { value: 'paid', label: 'Paid in full' },
    { value: 'partial', label: 'Partially paid' },
    { value: 'unpaid', label: 'Unpaid (on credit)' },
];

const CATEGORY_OPTIONS = [
    'Groceries',
    'Vegetables',
    'Fish & Meat',
    'Rice & Grains',
    'Cooking Gas',
    'Kitchen Supplies',
    'Utilities',
    'Other',
];

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
            {error || success}
        </div>
    );
}

/**
 * Meal module - Expenses list.
 *
 * PURPOSE
 * Expenses are what the kitchen spends on groceries and supplies. Like
 * deposits, each expense is mirrored to the ledger as a cash-out, so the pool
 * balance stays consistent. The page shows a month-scoped total, a "spend by
 * vendor" summary (for recurring shopping), a filterable table, and a
 * record/edit modal.
 *
 * PROPS (from the Laravel controller)
 *  - expenses: Laravel paginator { data, links, from, to, total }; each row
 *    carries a nested `transaction` (amount/reason), `vendor`, `recorder`.
 *  - categories: allowed category strings for the filter + form.
 *  - vendors: suppliers for the vendor <select>; one may be flagged
 *    is_institution_hub (the institution buying from itself).
 *  - filteredTotal: sum for the active filter (or the month when unfiltered).
 *  - byVendor: [{ name, total, count }] this month's spend per supplier.
 *  - months / month: the month selector's options and the selected value.
 *  - filters: { search, category, vendor, month } echo of the query.
 *
 * FLOW
 *  - Record / Edit: shared useForm; submit() post()s or put()s based on
 *    `editing`. Amount is read from the linked transaction on edit.
 *  - Reverse: router.patch(...reverse) keeps the row for audit and posts a
 *    matching cash-in so the money returns to the books.
 *  - Filtering: applyFilters() merges the new value into the query and does a
 *    partial router.get() visit.
 */
export default function Index({ expenses, categories, vendors, filteredTotal, byVendor, months, month, filters }) {
    const { can } = useCan();
    const { flash } = usePage().props;
    const money = useMoney();
    const { confirm } = useFeedback();
    const canRecord = can('meals.expense');

    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [search, setSearch] = useState(filters?.search || '');

    const { data, setData, post, put, processing, errors, reset, clearErrors } =
        useForm({ ...EMPTY_FORM });

    const isEditing = Boolean(editing);

    const rows = expenses?.data || [];

    // Vendor options: the institution hub comes first (it is the primary
    // supplier), then the rest alphabetically.
    const vendorOptions = useMemo(
        () => [
            { value: '', label: '— No vendor —' },
            ...(vendors || []).map((v) => ({
                value: String(v.id),
                label: v.is_institution_hub ? `${v.name} (hub)` : v.name,
            })),
        ],
        [vendors]
    );

    const categoryOptions = useMemo(
        () => CATEGORY_OPTIONS.map((c) => ({ value: c, label: c })),
        []
    );

    const openModal = () => {
        clearErrors();
        reset();
        setEditing(null);
        setData({ ...EMPTY_FORM });
        setModalOpen(true);
    };

    const openEdit = (expense) => {
        clearErrors();
        setEditing(expense);
        setData({
            amount: expense.transaction?.amount ?? expense.amount ?? '',
            description: expense.description || '',
            category: expense.category || 'Groceries',
            vendor_id: expense.vendor_id ? String(expense.vendor_id) : '',
            payment_status: expense.payment_status || 'paid',
            // Notes are stored on the transaction as its reason.
            notes: expense.transaction?.reason || '',
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
            put(route('meals.expenses.update', editing.id), {
                preserveScroll: true,
                onSuccess: () => closeModal(),
            });
            return;
        }

        post(route('meals.expenses.store'), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        });
    };

    // Reverse a recorded expense: keeps the row for audit, posts a cash-in.
    const reverse = async (expense) => {
        const ok = await confirm({
            title: 'Reverse this expense?',
            message: `A matching cash-in of ${money(expense.transaction?.amount ?? expense.amount ?? 0, false)} will be posted so the money returns to the books. The record is kept for the audit trail.`,
            tone: 'danger',
            confirmLabel: 'Reverse expense',
        });
        if (!ok) return;

        router.patch(route('meals.expenses.reverse', expense.id), {}, { preserveScroll: true });
    };

    const applyFilters = (next) => {
        router.get(
            route('meals.expenses.index'),
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true, replace: true }
        );
    };

    const hasFilters =
        Boolean(filters?.search) ||
        Boolean(filters?.category) ||
        Boolean(filters?.vendor) ||
        Boolean(filters?.month);

    return (
        <MealsLayout
            title="Expenses"
            description="Money spent on groceries and supplies. Each entry is also recorded as a cash-out transaction."
            actions={
                canRecord && (
                    <button
                        type="button"
                        onClick={openModal}
                        className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition-all hover:bg-indigo-700 active:bg-indigo-800"
                    >
                        <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Record Expense
                    </button>
                )
            }
        >
            <Head title="Expenses" />

            <Flash success={flash?.success} error={flash?.error} />

            {/* Month-scoped total, plus recurring spend per vendor. */}
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="rounded-xl border-slate-200 bg-white p-4 shadow-sm">
                    <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        {hasFilters ? 'Total for current filter' : 'Total spent this month'}
                    </div>
                    <div className="mt-1 text-2xl font-bold text-rose-600">
                        {money(filteredTotal, false)}
                    </div>
                </div>

                {/* Recurring shopping: how much went to each vendor this month. */}
                <div className="rounded-xl border-slate-200 bg-white p-4 shadow-sm lg:col-span-2">
                    <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        Spend by Vendor This Month
                    </div>
                    {(byVendor || []).length > 0 ? (
                        <div className="mt-2 flex-wrap gap-2">
                            {byVendor.slice(0, 6).map((v) => (
                                <span key={v.name} className="inline-flex items-center gap-1.5 rounded-full border-slate-200 bg-slate-50 px-3 py-1 text-xs">
                                    <span className="font-semibold text-slate-700">{v.name}</span>
                                    <span className="font-bold text-rose-600">{money(v.total, false)}</span>
                                    <span className="text-slate-400">· {v.count} order(s)</span>
                                </span>
                            ))}
                        </div>
                    ) : (
                        <p className="mt-1.5 text-xs text-slate-400">
                            Link a vendor when recording an expense to track recurring shopping here.
                        </p>
                    )}
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
                        className="relative max-w-xs flex-1"
                    >
                        <input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search description..."
                            className="w-full rounded-lg border-slate-300 py-2 pl-3 pr-3 text-sm outline-none transition-all focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                        />
                    </form>

                    {/* Month filter replaces the old date range. */}
                    <select
                        value={month || ''}
                        onChange={(event) => applyFilters({ month: event.target.value })}
                        aria-label="Report month"
                        className="rounded-lg border-slate-300 text-sm font-semibold text-slate-900 focus:border-[var(--accent)] focus:ring-[var(--accent-ring)]"
                    >
                        {(months || []).map((m) => (
                            <option key={m.value} value={m.value}>
                                {m.label}{m.current ? ' (current)' : ''}
                            </option>
                        ))}
                    </select>

                    <select
                        value={filters?.category || ''}
                        onChange={(event) => applyFilters({ category: event.target.value })}
                        className="rounded-lg border-slate-300 text-sm text-slate-900 focus:border-indigo-500 focus:ring-indigo-500"
                    >
                        <option value="">All categories</option>
                        {(categories || []).map((category) => (
                            <option key={category} value={category}>
                                {category}
                            </option>
                        ))}
                    </select>

                    {/* Vendor filter - surfaces a specific supplier's spend. */}
                    <select
                        value={filters?.vendor || ''}
                        onChange={(event) => applyFilters({ vendor: event.target.value })}
                        aria-label="Filter by vendor"
                        className="rounded-lg border-slate-300 text-sm text-slate-900 focus:border-indigo-500 focus:ring-indigo-500"
                    >
                        <option value="">All vendors</option>
                        {(vendors || []).map((v) => (
                            <option key={v.id} value={v.id}>{v.name}</option>
                        ))}
                    </select>

                    {hasFilters && (
                        <button
                            type="button"
                            onClick={() => {
                                setSearch('');
                                router.get(route('meals.expenses.index'), {}, { replace: true });
                            }}
                            className="text-xs font-semibold text-indigo-600 transition-colors hover:text-indigo-800"
                        >
                            Clear
                        </button>
                    )}
                </div>

                {/* Table */}
                <div className="overflow-x-auto">
                    <table className="w-full border-collapse text-left">
                        <thead>
                            <tr className="border-b border-slate-100 bg-slate-50 text-xs font-semibold uppercase tracking-wider text-slate-400">
                                <th className="px-6 py-3">Description</th>
                                <th className="px-6 py-3">Category</th>
                                <th className="px-6 py-3 text-right">Amount</th>
                                <th className="px-6 py-3">Recorded By</th>
                                <th className="px-6 py-3">Date</th>
                                {canRecord && <th className="px-6 py-3 text-right">Actions</th>}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 text-sm">
                            {rows.length > 0 ? (
                                rows.map((expense) => (
                                    <tr key={expense.id} className={`transition-colors hover:bg-slate-50/60 ${expense.reversed_at ? 'opacity-60' : ''}`}>
                                        <td className="px-6 py-4 font-semibold text-slate-900">
                                            {expense.description || 'Untitled expense'}
                                            {expense.reversed_at && (
                                                <span className="ml-2 inline-flex items-center rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-bold uppercase text-rose-600">
                                                    Reversed
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-6 py-4">
                                            {expense.category ? (
                                                <span className="inline-flex items-center rounded-full border-slate-200 bg-slate-50 px-2.5 py-0.5 text-xs font-medium text-slate-700">
                                                    {expense.category}
                                                </span>
                                            ) : (
                                                <span className="text-slate-300">—</span>
                                            )}
                                        </td>
                                        <td className={`px-6 py-4 text-right font-bold ${expense.reversed_at ? 'text-slate-400 line-through' : 'text-rose-600'}`}>
                                            −{money(expense.transaction?.amount ?? 0, false)}
                                        </td>
                                        <td className="px-6 py-4 text-slate-500">
                                            {expense.recorder?.name || '—'}
                                        </td>
                                        <td className="px-6 py-4 text-slate-500">
                                            {new Date(expense.created_at).toLocaleDateString()}
                                        </td>
                                        {canRecord && (
                                            <td className="whitespace-nowrap px-6 py-4 text-right">
                                                {!expense.reversed_at ? (
                                                    <>
                                                        <button
                                                            type="button"
                                                            onClick={() => openEdit(expense)}
                                                            className="font-medium text-indigo-600 transition-colors hover:text-indigo-900"
                                                        >
                                                            Edit
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => reverse(expense)}
                                                            className="ml-4 font-medium text-rose-500 transition-colors hover:text-rose-700"
                                                        >
                                                            Reverse
                                                        </button>
                                                    </>
                                                ) : (
                                                    <span className="text-xs italic text-slate-400">
                                                        Reversed {expense.reverser?.name ? `by ${expense.reverser.name}` : ''}
                                                    </span>
                                                )}
                                            </td>
                                        )}
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td colSpan={canRecord ? 6 : 5} className="py-14 text-center">
                                        <p className="text-sm font-semibold text-slate-600">
                                            {hasFilters ? 'No expenses match these filters.' : 'No expenses recorded yet.'}
                                        </p>
                                        <p className="mt-1 text-xs text-slate-400">
                                            Log what the kitchen spends on groceries and supplies.
                                        </p>
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Pagination */}
                {expenses?.links?.length > 3 && (
                    <div className="flex items-center justify-between gap-4 border-t border-slate-100 px-6 py-4">
                        <p className="text-xs text-slate-500">
                            Showing <strong>{expenses.from}</strong>–<strong>{expenses.to}</strong> of{' '}
                            <strong>{expenses.total}</strong>
                        </p>
                        <div className="flex flex-wrap gap-1">
                            {expenses.links.map((link, index) => (
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

            {/* Record expense modal */}
            <Modal
                open={modalOpen}
                onClose={closeModal}
                title={isEditing ? 'Edit Expense' : 'Record Expense'}
                description={isEditing
                    ? 'Update this expense. The change is mirrored onto its ledger transaction.'
                    : 'This creates a matching cash-out transaction in the ledger.'}
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
                            form="expense-form"
                            disabled={processing}
                            className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white shadow-sm transition-all hover:bg-indigo-700 active:bg-indigo-800 disabled:opacity-50"
                        >
                            {processing && (
                                <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                    <circle cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" className="opacity-25" />
                                    <path d="M4 12a8 8 0 018-8" stroke="currentColor" strokeWidth="4" strokeLinecap="round" className="opacity-90" />
                                </svg>
                            )}
                            {processing ? 'Saving...' : isEditing ? 'Save Changes' : 'Record Expense'}
                        </button>
                    </>
                }
            >
                <form id="expense-form" onSubmit={submit} className="space-y-4">
                    <Field
                        label="Description"
                        name="description"
                        required
                        value={data.description}
                        error={errors.description}
                        placeholder="e.g. Weekly vegetable market run"
                        onChange={(event) => setData('description', event.target.value)}
                    />

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Amount"
                            name="amount"
                            type="number"
                            required
                            step="0.01"
                            min="0.01"
                            value={data.amount}
                            error={errors.amount}
                            placeholder="e.g. 4500"
                            onChange={(event) => setData('amount', event.target.value)}
                        />

                        <Field
                            label="Category"
                            name="category"
                            type="select"
                            value={data.category}
                            error={errors.category}
                            options={categoryOptions}
                            onChange={(event) => setData('category', event.target.value)}
                        />
                    </div>

                    {/* Vendor + payment status: links recurring shopping to the
                        supplier and flags anything bought on credit. */}
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Vendor"
                            name="vendor_id"
                            type="select"
                            value={data.vendor_id}
                            error={errors.vendor_id}
                            hint="Pick the supplier for recurring shopping."
                            options={vendorOptions}
                            onChange={(event) => setData('vendor_id', event.target.value)}
                        />

                        <Field
                            label="Payment Status"
                            name="payment_status"
                            type="select"
                            value={data.payment_status}
                            error={errors.payment_status}
                            hint="Unpaid amounts count toward the vendor's balance."
                            options={PAYMENT_STATUSES}
                            onChange={(event) => setData('payment_status', event.target.value)}
                        />
                    </div>

                    <Field
                        label="Notes"
                        name="notes"
                        type="textarea"
                        value={data.notes}
                        error={errors.notes}
                        placeholder="Optional note about this purchase..."
                        onChange={(event) => setData('notes', event.target.value)}
                    />
                </form>
            </Modal>
        </MealsLayout>
    );
}
