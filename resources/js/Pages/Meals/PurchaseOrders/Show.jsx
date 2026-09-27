import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageHint, InfoHint } from '@/Components/Help/HelpHint';
import Field from '@/Components/UI/Field';
import Modal from '@/Components/UI/Modal';
import { Spinner } from '@/Components/UI/Loading';
import { useFeedback } from '@/Components/Feedback/FeedbackProvider';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';

/**
 * ONE PURCHASE ORDER — the three-way match workspace.
 *
 * This page is where the procure-to-pay control actually happens. For every line
 * it shows ORDERED vs RECEIVED vs BILLED side by side, flags any disagreement in
 * plain language, and offers the two actions that complete the chain: record goods
 * received, and record the vendor's invoice.
 */
export default function Show({ order, statuses = [], canApprove = false }) {
    const { flash } = usePage().props;
    const { confirm, prompt } = useFeedback();

    const [receiptOpen, setReceiptOpen] = useState(false);
    const [invoiceOpen, setInvoiceOpen] = useState(false);

    // The most recent invoice carries the match verdict for the page.
    const latestInvoice = order.invoices?.length > 0 ? order.invoices[order.invoices.length - 1] : null;
    const matchReport = latestInvoice?.match_report || null;

    const approve = async () => {
        const ok = await confirm({
            title: `Approve ${order.reference}?`,
            message: `This authorises ${order.total} of spend with ${order.vendor || 'the vendor'}.`,
            tone: 'info',
            confirmLabel: 'Approve order',
        });
        if (!ok) return;

        router.patch(route('meals.purchase-orders.approve', order.id), {}, { preserveScroll: true });
    };

    const advance = async (status) => {
        const ok = await confirm({
            title: `Mark as ${status}?`,
            message: 'This advances the order through its lifecycle.',
            tone: 'info',
            confirmLabel: `Mark ${status}`,
        });
        if (!ok) return;

        router.patch(route('meals.purchase-orders.status', order.id), { status }, { preserveScroll: true });
    };

    const rematch = (invoiceId) => {
        router.post(route('meals.purchase-orders.invoices.match', invoiceId), {}, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <Link href={route('meals.purchase-orders.index')} className="text-xs font-semibold text-slate-400 hover:text-slate-600">
                            ← Purchase Orders
                        </Link>
                        <div className="mt-1 flex flex-wrap items-center gap-2">
                            <StatusChip tone={order.status_tone} label={order.status} />
                            <span className="font-mono text-xs text-slate-500">{order.reference}</span>
                            <span className="text-xs text-slate-500">{order.vendor || 'No vendor'}</span>
                        </div>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        {canApprove && order.is_approvable && (
                            <button
                                type="button"
                                onClick={approve}
                                data-testid="po-approve"
                                className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-700"
                            >
                                Approve
                            </button>
                        )}
                        {order.status === 'approved' && (
                            <button
                                type="button"
                                onClick={() => advance('ordered')}
                                className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50"
                            >
                                Mark as ordered
                            </button>
                        )}
                        <button
                            type="button"
                            onClick={() => setReceiptOpen(true)}
                            data-testid="po-receipt-open"
                            className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50"
                        >
                            Record receipt
                        </button>
                        <button
                            type="button"
                            onClick={() => setInvoiceOpen(true)}
                            data-testid="po-invoice-open"
                            className="rounded-lg bg-[var(--accent)] px-4 py-2 text-sm font-semibold text-white transition-opacity hover:opacity-90"
                        >
                            Record invoice
                        </button>
                    </div>
                </div>
            }
        >
            <Head title={`Purchase Order ${order.reference}`} />

            <div className="space-y-6">
                <PageHint title="Ordered vs received vs billed">
                    A line is clear to pay only when all three agree. Anything that disagrees is flagged with the
                    exact reason, so you can resolve it before money leaves the account.
                </PageHint>

                {flash?.success && (
                    <div role="status" data-testid="po-detail-flash" className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
                        {flash.error}
                    </div>
                )}

                {/* ---- Totals ---- */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-4">
                    <Summary label="Subtotal" value={order.subtotal} />
                    <Summary label="Tax" value={order.tax} />
                    <Summary label="Total" value={order.total} tone="text-slate-900" />
                    <Summary
                        label="Invoice variance"
                        value={matchReport?.summary?.variance_total ?? 0}
                        tone={(matchReport?.summary?.variance_total ?? 0) === 0 ? 'text-emerald-600' : 'text-rose-600'}
                        testid="po-variance"
                    />
                </div>

                {/* ---- Match verdict ---- */}
                {matchReport && (
                    <div
                        data-testid="po-match-verdict"
                        className={`rounded-xl border p-4 ${matchReport.status === 'matched'
                            ? 'border-emerald-200 bg-emerald-50'
                            : 'border-rose-200 bg-rose-50'}`}
                    >
                        <p className={`text-sm font-bold ${matchReport.status === 'matched' ? 'text-emerald-800' : 'text-rose-800'}`}>
                            {matchReport.status === 'matched' ? 'All three documents agree' : 'Variance detected'}
                        </p>
                        <p className={`mt-0.5 text-xs ${matchReport.status === 'matched' ? 'text-emerald-700' : 'text-rose-700'}`}>
                            {matchReport.summary?.message}
                        </p>
                    </div>
                )}

                {/* ---- The three-way match table ---- */}
                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs">
                    <div className="border-b border-slate-100 bg-slate-50/70 px-6 py-3">
                        <h3 className="text-sm font-bold text-slate-900">Line-by-line match</h3>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-slate-50 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                <tr>
                                    <th className="px-6 py-2">Item</th>
                                    <th className="px-6 py-2">Ordered</th>
                                    <th className="px-6 py-2">Received</th>
                                    <th className="px-6 py-2">Billed</th>
                                    <th className="px-6 py-2">Unit price</th>
                                    <th className="px-6 py-2">Line total</th>
                                    <th className="px-6 py-2">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {(matchReport?.lines || order.items)?.map((line, index) => {
                                    // Before any invoice exists, the table falls back to the
                                    // ORDERED lines so the page is still useful.
                                    const isMatchLine = 'clear' in line;

                                    return (
                                        <tr key={index} data-testid="po-line-row" className={isMatchLine && !line.clear ? 'bg-rose-50/40' : ''}>
                                            <td className="px-6 py-3">
                                                <p className="text-xs font-semibold text-slate-800">
                                                    {line.description || line.name}
                                                </p>
                                                {isMatchLine && line.issues?.length > 0 && (
                                                    <ul className="mt-1 list-disc space-y-0.5 pl-4 text-[10px] text-rose-600">
                                                        {line.issues.map((issue, i) => (
                                                            <li key={i}>{issue}</li>
                                                        ))}
                                                    </ul>
                                                )}
                                            </td>
                                            <td className="px-6 py-3 text-xs text-slate-600">
                                                {isMatchLine ? line.quantity_ordered : line.quantity} {line.unit}
                                            </td>
                                            <td className="px-6 py-3 text-xs text-slate-600">
                                                {isMatchLine ? line.quantity_received : '—'}
                                            </td>
                                            <td className="px-6 py-3 text-xs text-slate-600">
                                                {isMatchLine ? line.quantity_billed : '—'}
                                            </td>
                                            <td className="px-6 py-3 text-xs text-slate-500">
                                                {isMatchLine ? line.unit_price_billed : line.unit_price}
                                            </td>
                                            <td className="px-6 py-3 text-xs font-bold text-slate-900">
                                                {isMatchLine ? line.line_total : line.line_total}
                                            </td>
                                            <td className="px-6 py-3">
                                                {isMatchLine ? (
                                                    <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-bold uppercase ${line.clear
                                                        ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                                        : 'border-rose-200 bg-rose-50 text-rose-700'}`}>
                                                        {line.clear ? 'Clear' : 'Variance'}
                                                    </span>
                                                ) : (
                                                    <span className="text-[10px] text-slate-400">Not billed</span>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    {/* ---- Receipts ---- */}
                    <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs">
                        <div className="border-b border-slate-100 bg-slate-50/70 px-6 py-3">
                            <h3 className="text-sm font-bold text-slate-900">Goods received ({order.receipts?.length || 0})</h3>
                        </div>

                        {order.receipts?.length > 0 ? (
                            <div className="divide-y divide-slate-100">
                                {order.receipts.map((receipt) => (
                                    <div key={receipt.id} data-testid="po-receipt-row" className="px-6 py-3">
                                        <div className="flex items-center justify-between">
                                            <span className="text-xs font-semibold text-slate-700">{receipt.reference}</span>
                                            <span className="text-[11px] text-slate-400">{receipt.received_on}</span>
                                        </div>
                                        <p className="mt-0.5 text-[11px] text-slate-500">
                                            {receipt.lines?.length || 0} line(s)
                                            {receipt.receiver ? ` · by ${receipt.receiver}` : ''}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <p className="px-6 py-8 text-center text-xs text-slate-400">
                                Nothing received yet.
                            </p>
                        )}
                    </div>

                    {/* ---- Invoices ---- */}
                    <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs">
                        <div className="border-b border-slate-100 bg-slate-50/70 px-6 py-3">
                            <h3 className="text-sm font-bold text-slate-900">Vendor invoices ({order.invoices?.length || 0})</h3>
                        </div>

                        {order.invoices?.length > 0 ? (
                            <div className="divide-y divide-slate-100">
                                {order.invoices.map((invoice) => (
                                    <div key={invoice.id} data-testid="po-invoice-row" className="flex items-center justify-between gap-3 px-6 py-3">
                                        <div className="min-w-0">
                                            <p className="text-xs font-semibold text-slate-700">{invoice.reference}</p>
                                            <p className="mt-0.5 text-[11px] text-slate-500">
                                                {invoice.total} · {invoice.invoiced_on}
                                            </p>
                                        </div>
                                        <div className="flex flex-shrink-0 items-center gap-2">
                                            <StatusChip tone={invoice.match_tone} label={invoice.match_status} />
                                            <button
                                                type="button"
                                                onClick={() => rematch(invoice.id)}
                                                className="text-[11px] font-semibold text-[var(--accent)] hover:underline"
                                            >
                                                Re-match
                                            </button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <p className="px-6 py-8 text-center text-xs text-slate-400">
                                No invoice recorded yet.
                            </p>
                        )}
                    </div>
                </div>

                {order.notes && (
                    <div className="rounded-xl border border-slate-200 bg-white p-4 text-xs text-slate-600">
                        <strong className="font-semibold text-slate-700">Notes:</strong> {order.notes}
                    </div>
                )}

                <InfoHint tone="indigo">
                    Recording a receipt refreshes the match automatically. If a line still disagrees, review it
                    with the vendor before approving payment.
                </InfoHint>
            </div>

            <ReceiptModal open={receiptOpen} onClose={() => setReceiptOpen(false)} order={order} />
            <InvoiceModal open={invoiceOpen} onClose={() => setInvoiceOpen(false)} order={order} />
        </AuthenticatedLayout>
    );
}

function Summary({ label, value, tone = 'text-slate-700', testid = null }) {
    return (
        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">{label}</p>
            <p data-testid={testid} className={`mt-1 text-xl font-bold ${tone}`}>{value}</p>
        </div>
    );
}

function StatusChip({ tone, label }) {
    const tones = {
        emerald: 'border-emerald-200 bg-emerald-50 text-emerald-700',
        sky: 'border-sky-200 bg-sky-50 text-sky-700',
        rose: 'border-rose-200 bg-rose-50 text-rose-700',
        amber: 'border-amber-200 bg-amber-50 text-amber-700',
        slate: 'border-slate-200 bg-slate-100 text-slate-600',
    };

    return (
        <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-[10px] font-bold uppercase ${tones[tone] || tones.slate}`}>
            {label}
        </span>
    );
}

/** Record goods received against the order's lines. */
function ReceiptModal({ open, onClose, order }) {
    const { data, setData, post, processing, errors } = useForm({
        reference: '',
        received_on: new Date().toISOString().slice(0, 10),
        notes: '',
        lines: order.items.map((item) => ({ item_id: item.id, quantity: item.quantity })),
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('meals.purchase-orders.receipts.store', order.id), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    const setQty = (index, value) => {
        const next = [...data.lines];
        next[index] = { ...next[index], quantity: value };
        setData('lines', next);
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            title="Record goods received"
            description="What actually arrived. Defaults to the ordered quantity."
            maxWidth="max-w-xl"
            footer={
                <>
                    <button type="button" onClick={onClose} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                        Cancel
                    </button>
                    <button
                        type="submit"
                        form="receipt-form"
                        disabled={processing}
                        data-testid="receipt-submit"
                        className="inline-flex items-center gap-2 rounded-lg bg-[var(--accent)] px-5 py-2 text-sm font-medium text-white hover:opacity-90 disabled:opacity-50"
                    >
                        {processing && <Spinner className="h-4 w-4" />}
                        {processing ? 'Saving...' : 'Record receipt'}
                    </button>
                </>
            }
        >
            <form id="receipt-form" onSubmit={submit} className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Reference" name="reference" value={data.reference} error={errors.reference} placeholder="e.g. GRN-001" onChange={(e) => setData('reference', e.target.value)} />
                    <Field label="Received on" name="received_on" type="date" required value={data.received_on} error={errors.received_on} onChange={(e) => setData('received_on', e.target.value)} />
                </div>

                <div className="space-y-2">
                    <p className="text-xs font-bold uppercase tracking-wider text-[var(--accent)]">Quantities received</p>
                    {order.items.map((item, index) => (
                        <div key={item.id} className="flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50/60 p-2.5">
                            <span className="min-w-0 flex-1 text-xs text-slate-700">{item.description}</span>
                            <span className="text-[11px] text-slate-400">ordered {item.quantity} {item.unit}</span>
                            <input
                                type="number"
                                step="0.001"
                                value={data.lines[index]?.quantity ?? ''}
                                onChange={(e) => setQty(index, e.target.value)}
                                data-testid={`receipt-qty-${index}`}
                                className="w-24 rounded-lg border-slate-300 text-xs"
                            />
                        </div>
                    ))}
                </div>

                <Field label="Notes" name="notes" type="textarea" value={data.notes} error={errors.notes} onChange={(e) => setData('notes', e.target.value)} />
            </form>
        </Modal>
    );
}

/** Record the vendor's invoice; the match runs on submit. */
function InvoiceModal({ open, onClose, order }) {
    const { data, setData, post, processing, errors } = useForm({
        reference: '',
        invoiced_on: new Date().toISOString().slice(0, 10),
        due_on: '',
        tax: 0,
        notes: '',
        lines: order.items.map((item) => ({ item_id: item.id, quantity: item.quantity, unit_price: item.unit_price })),
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('meals.purchase-orders.invoices.store', order.id), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    const setLine = (index, key, value) => {
        const next = [...data.lines];
        next[index] = { ...next[index], [key]: value };
        setData('lines', next);
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            title="Record vendor invoice"
            description="What the vendor billed. The three-way match runs when you save."
            maxWidth="max-w-2xl"
            footer={
                <>
                    <button type="button" onClick={onClose} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                        Cancel
                    </button>
                    <button
                        type="submit"
                        form="invoice-form"
                        disabled={processing}
                        data-testid="invoice-submit"
                        className="inline-flex items-center gap-2 rounded-lg bg-[var(--accent)] px-5 py-2 text-sm font-medium text-white hover:opacity-90 disabled:opacity-50"
                    >
                        {processing && <Spinner className="h-4 w-4" />}
                        {processing ? 'Matching...' : 'Save & match'}
                    </button>
                </>
            }
        >
            <form id="invoice-form" onSubmit={submit} className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-3">
                    <Field label="Invoice number" name="reference" required value={data.reference} error={errors.reference} placeholder="Vendor's ref" onChange={(e) => setData('reference', e.target.value)} />
                    <Field label="Invoiced on" name="invoiced_on" type="date" required value={data.invoiced_on} error={errors.invoiced_on} onChange={(e) => setData('invoiced_on', e.target.value)} />
                    <Field label="Due on" name="due_on" type="date" value={data.due_on} error={errors.due_on} onChange={(e) => setData('due_on', e.target.value)} />
                </div>

                <div className="space-y-2">
                    <p className="text-xs font-bold uppercase tracking-wider text-[var(--accent)]">Billed quantities &amp; prices</p>
                    {order.items.map((item, index) => (
                        <div key={item.id} className="grid gap-2 rounded-lg border border-slate-200 bg-slate-50/60 p-2.5 sm:grid-cols-12">
                            <span className="text-xs text-slate-700 sm:col-span-5">{item.description}</span>
                            <input
                                type="number"
                                step="0.001"
                                value={data.lines[index]?.quantity ?? ''}
                                onChange={(e) => setLine(index, 'quantity', e.target.value)}
                                data-testid={`invoice-qty-${index}`}
                                placeholder="Qty"
                                className="rounded-lg border-slate-300 text-xs sm:col-span-3"
                            />
                            <input
                                type="number"
                                step="0.0001"
                                value={data.lines[index]?.unit_price ?? ''}
                                onChange={(e) => setLine(index, 'unit_price', e.target.value)}
                                data-testid={`invoice-price-${index}`}
                                placeholder="Unit price"
                                className="rounded-lg border-slate-300 text-xs sm:col-span-4"
                            />
                        </div>
                    ))}
                </div>

                <Field label="Tax" name="tax" type="number" step="0.01" value={data.tax} error={errors.tax} onChange={(e) => setData('tax', e.target.value)} />
                <Field label="Notes" name="notes" type="textarea" value={data.notes} error={errors.notes} onChange={(e) => setData('notes', e.target.value)} />
            </form>
        </Modal>
    );
}