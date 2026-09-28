import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageHint, InfoHint } from '@/Components/Help/HelpHint';
import Field from '@/Components/UI/Field';
import Modal from '@/Components/UI/Modal';
import { Spinner } from '@/Components/UI/Loading';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';

/**
 * PURCHASE ORDERS (list + create).
 *
 * The first leg of the procure-to-pay chain. A PO is raised here, APPROVED by an
 * Institution Admin, then matched against goods receipts and the vendor's invoice
 * on the detail page.
 */
export default function Index({ orders, vendors = [], statuses = [], filters = {}, summary = {} }) {
    const { flash } = usePage().props;

    const [createOpen, setCreateOpen] = useState(false);

    const rows = orders?.data || [];

    const applyFilter = (next) => {
        router.get(route('meals.purchase-orders.index'), { ...filters, ...next }, {
            preserveState: true, preserveScroll: true, replace: true,
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p className="text-xs font-medium text-slate-500">
                            Raise orders, record what arrives, and match them against the vendor's invoice.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={() => setCreateOpen(true)}
                        data-testid="po-create-button"
                        className="inline-flex items-center gap-2 self-start rounded-lg bg-[var(--accent)] px-4 py-2 text-sm font-semibold text-white shadow-sm transition-opacity hover:opacity-90"
                    >
                        New purchase order
                    </button>
                </div>
            }
        >
            <Head title="Purchase Orders" />

            <div className="space-y-5">
                <PageHint title="The three-way match">
                    A vendor should only be paid when what was <strong className="font-semibold text-slate-700">ordered</strong>,
                    what <strong className="font-semibold text-slate-700">arrived</strong> and what was{' '}
                    <strong className="font-semibold text-slate-700">billed</strong> all agree. Open an order to
                    record a receipt and an invoice - the match runs automatically.
                </PageHint>

                {flash?.success && (
                    <div role="status" data-testid="po-flash" className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
                        {flash.error}
                    </div>
                )}

                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    {[
                        { label: 'Open orders', value: summary.open ?? 0, tone: 'text-sky-600' },
                        { label: 'Awaiting approval', value: summary.awaiting_approval ?? 0, tone: 'text-amber-600' },
                        { label: 'Invoice variances', value: summary.variance_invoices ?? 0, tone: 'text-rose-600' },
                        { label: 'Committed value', value: summary.committed_value ?? 0, tone: 'text-slate-900' },
                    ].map((card) => (
                        <div key={card.label} className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                            <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">{card.label}</p>
                            <p className={`mt-1 text-2xl font-bold ${card.tone}`}>{card.value}</p>
                        </div>
                    ))}
                </div>

                <div className="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3">
                    <select
                        value={filters.status || ''}
                        onChange={(e) => applyFilter({ status: e.target.value })}
                        data-testid="po-status-filter"
                        className="rounded-lg border-slate-300 text-sm text-slate-900 focus:border-[var(--accent)] focus:ring-[var(--accent)]"
                    >
                        <option value="">All statuses</option>
                        {statuses.map((status) => (
                            <option key={status} value={status}>{status}</option>
                        ))}
                    </select>
                    <select
                        value={filters.vendor || ''}
                        onChange={(e) => applyFilter({ vendor: e.target.value })}
                        className="rounded-lg border-slate-300 text-sm text-slate-900 focus:border-[var(--accent)] focus:ring-[var(--accent)]"
                    >
                        <option value="">All vendors</option>
                        {vendors.map((vendor) => (
                            <option key={vendor.id} value={vendor.id}>{vendor.name}</option>
                        ))}
                    </select>
                </div>

                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                <tr>
                                    <th className="px-6 py-3">Reference</th>
                                    <th className="px-6 py-3">Vendor</th>
                                    <th className="px-6 py-3">Items</th>
                                    <th className="px-6 py-3">Total</th>
                                    <th className="px-6 py-3">Status</th>
                                    <th className="px-6 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {rows.length > 0 ? rows.map((order) => (
                                    <tr key={order.id} data-testid="po-row">
                                        <td className="px-6 py-4">
                                            <p className="font-mono text-xs font-semibold text-slate-700">{order.reference}</p>
                                            <p className="text-[11px] text-slate-400">{order.created_at}</p>
                                        </td>
                                        <td className="px-6 py-4 text-xs text-slate-600">{order.vendor || '—'}</td>
                                        <td className="px-6 py-4 text-xs text-slate-600">{order.items_count}</td>
                                        <td className="px-6 py-4 text-xs font-bold text-slate-900">{order.total}</td>
                                        <td className="px-6 py-4">
                                            <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-[10px] font-bold uppercase ${order.status_tone === 'emerald'
                                                ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                                : order.status_tone === 'sky'
                                                    ? 'border-sky-200 bg-sky-50 text-sky-700'
                                                    : order.status_tone === 'rose'
                                                        ? 'border-rose-200 bg-rose-50 text-rose-700'
                                                        : 'border-slate-200 bg-slate-100 text-slate-600'}`}>
                                                {order.status}
                                            </span>
                                            {order.approver && (
                                                <p className="mt-1 text-[10px] text-slate-400">by {order.approver}</p>
                                            )}
                                        </td>
                                        <td className="px-6 py-4 text-right">
                                            <Link
                                                href={route('meals.purchase-orders.show', order.id)}
                                                className="text-xs font-semibold text-[var(--accent)] hover:underline"
                                            >
                                                Open
                                            </Link>
                                        </td>
                                    </tr>
                                                )) : (
                                                    <tr>
                                                        <td colSpan={6} className="px-6 py-12 text-center text-xs text-slate-400">
                                                            No purchase orders yet.
                                                        </td>
                                                    </tr>
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                    {orders?.links?.length > 3 && (
                        <div className="flex flex-wrap gap-1">
                            {orders.links.map((link, index) => (
                                <Link
                                    key={index}
                                    href={link.url || '#'}
                                    preserveScroll
                                    className={`rounded-lg border px-3 py-1.5 text-xs font-semibold ${link.active
                                        ? 'border-[var(--accent)] bg-[var(--accent)] text-white'
                                        : link.url
                                            ? 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
                                            : 'border-slate-100 bg-white text-slate-300 pointer-events-none'}`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    )}

                    <InfoHint tone="amber">
                        Only an <strong>Institution Admin</strong> can approve a purchase order - a Meal Manager may
                        raise one but not authorise the spend.
                    </InfoHint>
                </div>

                <CreateOrderModal open={createOpen} onClose={() => setCreateOpen(false)} vendors={vendors} />
        </AuthenticatedLayout>
    );
}

/** Raise a PO with its line items. */
function CreateOrderModal({ open, onClose, vendors = [] }) {
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        vendor_id: vendors[0]?.id || '',
        expected_on: '',
        tax: 0,
        notes: '',
        submit: true,
        items: [{ description: '', unit: 'kg', quantity: '', unit_price: '' }],
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('meals.purchase-orders.store'), {
            preserveScroll: true,
            onSuccess: () => { reset(); clearErrors(); onClose(); },
        });
    };

    const setItem = (index, key, value) => {
        const next = [...data.items];
        next[index] = { ...next[index], [key]: value };
        setData('items', next);
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            title="New purchase order"
            description="What you are ordering, and at what price."
            maxWidth="max-w-3xl"
            footer={
                <>
                    <button type="button" onClick={onClose} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                        Cancel
                    </button>
                    <button
                        type="submit"
                        form="po-create-form"
                        disabled={processing}
                        data-testid="po-submit"
                        className="inline-flex items-center gap-2 rounded-lg bg-[var(--accent)] px-5 py-2 text-sm font-medium text-white hover:opacity-90 disabled:opacity-50"
                    >
                        {processing && <Spinner className="h-4 w-4" />}
                        {processing ? 'Creating...' : 'Create order'}
                    </button>
                </>
            }
        >
            <form id="po-create-form" onSubmit={submit} className="space-y-5">
                <div className="grid gap-4 sm:grid-cols-3">
                    <Field
                        label="Vendor"
                        name="vendor_id"
                        type="select"
                        required
                        value={data.vendor_id}
                        error={errors.vendor_id}
                        options={vendors.map((v) => ({ value: v.id, label: v.name }))}
                        onChange={(e) => setData('vendor_id', e.target.value)}
                    />
                    <Field
                        label="Expected on"
                        name="expected_on"
                        type="date"
                        value={data.expected_on}
                        error={errors.expected_on}
                        onChange={(e) => setData('expected_on', e.target.value)}
                    />
                    <Field
                        label="Tax"
                        name="tax"
                        type="number"
                        step="0.01"
                        value={data.tax}
                        error={errors.tax}
                        onChange={(e) => setData('tax', e.target.value)}
                    />
                </div>

                <div className="space-y-3">
                    <div className="flex items-center justify-between">
                        <p className="text-xs font-bold uppercase tracking-wider text-[var(--accent)]">
                            Line items ({data.items.length})
                        </p>
                        <button
                            type="button"
                            onClick={() => setData('items', [...data.items, { description: '', unit: 'kg', quantity: '', unit_price: '' }])}
                            className="text-xs font-semibold text-[var(--accent)] hover:underline"
                        >
                            + Add line
                        </button>
                    </div>

                    {data.items.map((item, index) => (
                        <div key={index} className="grid gap-2 rounded-xl border border-slate-200 bg-slate-50/60 p-3 sm:grid-cols-12">
                            <input
                                value={item.description}
                                onChange={(e) => setItem(index, 'description', e.target.value)}
                                placeholder="Description"
                                data-testid={`po-item-desc-${index}`}
                                className="rounded-lg border-slate-300 text-xs sm:col-span-5"
                            />
                            <input
                                value={item.unit}
                                onChange={(e) => setItem(index, 'unit', e.target.value)}
                                placeholder="Unit"
                                className="rounded-lg border-slate-300 text-xs sm:col-span-2"
                            />
                            <input
                                type="number"
                                step="0.001"
                                value={item.quantity}
                                onChange={(e) => setItem(index, 'quantity', e.target.value)}
                                placeholder="Qty"
                                data-testid={`po-item-qty-${index}`}
                                className="rounded-lg border-slate-300 text-xs sm:col-span-2"
                            />
                            <input
                                type="number"
                                step="0.0001"
                                value={item.unit_price}
                                onChange={(e) => setItem(index, 'unit_price', e.target.value)}
                                placeholder="Unit price"
                                data-testid={`po-item-price-${index}`}
                                className="rounded-lg border-slate-300 text-xs sm:col-span-3"
                            />
                        </div>
                    ))}

                    {errors.items && <p className="text-xs font-medium text-rose-600">{errors.items}</p>}
                </div>

                <Field
                    label="Notes"
                    name="notes"
                    type="textarea"
                    value={data.notes}
                    error={errors.notes}
                    onChange={(e) => setData('notes', e.target.value)}
                />

                <label className="flex cursor-pointer items-center gap-2 text-sm font-semibold text-slate-700">
                    <input
                        type="checkbox"
                        checked={data.submit}
                        onChange={(e) => setData('submit', e.target.checked)}
                        className="h-4 w-4 rounded border-slate-300 text-[var(--accent)]"
                    />
                    Submit for approval now
                </label>
            </form>
        </Modal>
    );
}