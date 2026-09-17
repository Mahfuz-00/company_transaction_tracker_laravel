import React, { useEffect, useState } from 'react';
import Modal from '@/Components/UI/Modal';
import useMoney from '@/Utils/useMoney';

/**
 * Purchase History modal for a vendor, extracted from the Vendors page.
 *
 * Owns its own fetch + loading state so the parent just passes the selected
 * vendor. The full ledger is loaded on open and rendered as a table.
 */
export default function PurchaseHistoryModal({ open, vendor, onClose }) {
    const money = useMoney();
    const [history, setHistory] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(false);

    useEffect(() => {
        if (!open || !vendor?.slug) {
            setHistory(null);
            return;
        }

        let cancelled = false;
        setLoading(true);
        setError(false);

        window.axios
            .get(route('meals.vendors.history', vendor.slug))
            .then(({ data }) => {
                if (!cancelled) setHistory(data);
            })
            .catch(() => {
                if (!cancelled) setError(true);
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });

        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, vendor?.slug]);

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={vendor ? `Purchase History - ${vendor.name}` : 'Purchase History'}
            description="Every recorded purchase tied to this supplier, newest first."
            maxWidth="max-w-3xl"
            footer={
                <button
                    type="button"
                    onClick={onClose}
                    className="rounded-lg border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-100"
                >
                    Close
                </button>
            }
        >
            {loading ? (
                <div className="py-10 text-center text-sm text-slate-400">Loading purchase history...</div>
            ) : error ? (
                <div className="py-10 text-center text-sm text-rose-500">
                    Could not load purchase history. Please try again.
                </div>
            ) : history ? (
                <div className="space-y-4">
                    <div className="grid grid-cols-3 gap-3">
                        {[
                            { label: 'Orders', value: history.totals.orders },
                            { label: 'Total Purchased', value: money(history.totals.purchased, false) },
                            { label: 'Outstanding', value: money(history.totals.outstanding, false) },
                        ].map((cell) => (
                            <div key={cell.label} className="rounded-lg border-slate-200 bg-slate-50 p-3">
                                <div className="text-[10px] font-semibold uppercase tracking-wider text-slate-400">{cell.label}</div>
                                <div className="mt-0.5 text-lg font-bold text-slate-800">{cell.value}</div>
                            </div>
                        ))}
                    </div>

                    {history.history.length > 0 ? (
                        <div className="overflow-x-auto rounded-lg border-slate-200">
                            <table className="w-full text-left text-sm">
                                <thead>
                                    <tr className="border-b border-slate-100 bg-slate-50 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                                        <th className="px-4 py-2.5">Date</th>
                                        <th className="px-4 py-2.5">Item</th>
                                        <th className="px-4 py-2.5">Category</th>
                                        <th className="px-4 py-2.5">Status</th>
                                        <th className="px-4 py-2.5 text-right">Amount</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {history.history.map((row) => (
                                        <tr key={row.id}>
                                            <td className="whitespace-nowrap px-4 py-2.5 text-xs text-slate-500">{row.date}</td>
                                            <td className="px-4 py-2.5 font-medium text-slate-800">{row.description}</td>
                                            <td className="px-4 py-2.5 text-xs text-slate-500">{row.category || '—'}</td>
                                            <td className="px-4 py-2.5">
                                                <span className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-bold uppercase ${row.payment_status === 'paid'
                                                    ? 'bg-emerald-50 text-emerald-700'
                                                    : row.payment_status === 'partial'
                                                        ? 'bg-amber-50 text-amber-700'
                                                        : 'bg-rose-50 text-rose-700'}`}>
                                                    {row.payment_status || 'paid'}
                                                </span>
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-2.5 text-right font-bold text-rose-600">
                                                −{money(row.amount, false)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <p className="py-8 text-center text-sm italic text-slate-400">
                            No purchases recorded with this vendor yet.
                        </p>
                    )}
                </div>
            ) : null}
        </Modal>
    );
}
