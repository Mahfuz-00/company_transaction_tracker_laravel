import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageHint, InfoHint } from '@/Components/Help/HelpHint';
import { useFeedback } from '@/Components/Feedback/FeedbackProvider';
import { Head, Link, router, usePage } from '@inertiajs/react';

/**
 * MEMBER PAYMENT VERIFICATION (staff queue).
 *
 * Where a Meal Manager / Institution Admin confirms the payments members have
 * submitted. Approving is the moment money actually moves: the controller creates
 * a real Deposit, so the member's balance, the roster and the reports all agree.
 */
export default function Index({ payments, filters = {}, summary = {} }) {
    const { flash } = usePage().props;
    const { confirm, prompt } = useFeedback();

    const rows = payments?.data || [];

    const applyFilter = (status) => {
        router.get(route('meals.member-payments.index'), { status }, {
            preserveState: true, preserveScroll: true, replace: true,
        });
    };

    const approve = async (payment) => {
        const ok = await confirm({
            title: `Approve ${payment.reference}?`,
            message: `This records a deposit of ${payment.amount} for ${payment.student?.name} and updates their balance immediately.`,
            tone: 'info',
            confirmLabel: 'Approve & credit',
        });
        if (!ok) return;

        router.patch(route('meals.member-payments.approve', payment.id), {}, { preserveScroll: true });
    };

    const reject = async (payment) => {
        const note = await prompt({
            title: `Reject ${payment.reference}?`,
            message: 'Give the member a reason - they will see it on their payments page.',
            placeholder: 'e.g. no matching bKash transaction',
            tone: 'warning',
            confirmLabel: 'Reject payment',
        });

        if (!note) return;

        router.patch(route('meals.member-payments.reject', payment.id), { review_note: note }, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <p className="text-xs font-medium text-slate-500">
                        Confirm the payments members have submitted. Approving credits their balance.
                    </p>
                </div>
            }
        >
            <Head title="Payment Verification" />

            <div className="space-y-5">
                <PageHint title="A member's payment is a claim until you verify it">
                    Members submit their own top-ups, but nothing touches their balance until you approve it
                    here. Check the reference against your records before confirming.
                </PageHint>

                {flash?.success && (
                    <div role="status" data-testid="payment-queue-flash" className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
                        {flash.error}
                    </div>
                )}

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    {[
                        { label: 'Awaiting verification', value: summary.pending ?? 0, tone: 'text-amber-600' },
                        { label: 'Pending value', value: summary.pending_total ?? 0, tone: 'text-slate-900' },
                        { label: 'Approved this month', value: summary.approved_this_month ?? 0, tone: 'text-emerald-600' },
                    ].map((card) => (
                        <div key={card.label} className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                            <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">{card.label}</p>
                            <p className={`mt-1 text-2xl font-bold ${card.tone}`}>{card.value}</p>
                        </div>
                    ))}
                </div>

                <div className="flex flex-wrap gap-2">
                    {[
                        { value: 'pending', label: 'Pending' },
                        { value: 'approved', label: 'Approved' },
                        { value: 'rejected', label: 'Rejected' },
                        { value: 'all', label: 'All' },
                    ].map((option) => (
                        <button
                            key={option.value}
                            type="button"
                            onClick={() => applyFilter(option.value)}
                            data-testid={`payment-filter-${option.value}`}
                            className={`rounded-lg border px-3.5 py-1.5 text-xs font-semibold transition-colors ${(filters.status || 'pending') === option.value
                                ? 'border-[var(--accent)] bg-[var(--accent)] text-white'
                                : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50'}`}
                        >
                            {option.label}
                        </button>
                    ))}
                </div>

                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                <tr>
                                    <th className="px-6 py-3">Member</th>
                                    <th className="px-6 py-3">Reference</th>
                                    <th className="px-6 py-3">Amount</th>
                                    <th className="px-6 py-3">Method</th>
                                    <th className="px-6 py-3">Status</th>
                                    <th className="px-6 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {rows.length > 0 ? rows.map((payment) => (
                                    <tr key={payment.id} data-testid="payment-queue-row">
                                        <td className="px-6 py-4">
                                            <p className="text-xs font-bold text-slate-800">{payment.student?.name}</p>
                                            <p className="text-[11px] text-slate-400">{payment.student?.roll || 'no roll'}</p>
                                        </td>
                                        <td className="px-6 py-4">
                                            <p className="font-mono text-xs text-slate-600">{payment.reference}</p>
                                            {payment.payer_reference && (
                                                <p className="text-[11px] text-slate-400">ref: {payment.payer_reference}</p>
                                            )}
                                        </td>
                                        <td className="px-6 py-4 text-xs font-bold text-slate-900">{payment.amount}</td>
                                        <td className="px-6 py-4 text-xs text-slate-500">
                                            {payment.method_label}
                                            {payment.note && (
                                                <p className="mt-0.5 max-w-[12rem] text-[10px] italic text-slate-400">{payment.note}</p>
                                            )}
                                        </td>
                                        <td className="px-6 py-4">
                                            <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-[10px] font-bold uppercase ${payment.status_tone === 'emerald'
                                                ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                                : payment.status_tone === 'rose'
                                                    ? 'border-rose-200 bg-rose-50 text-rose-700'
                                                    : 'border-amber-200 bg-amber-50 text-amber-700'}`}>
                                                {payment.status}
                                            </span>
                                            {payment.review_note && (
                                                <p className="mt-1 max-w-[12rem] text-[10px] text-slate-400">{payment.review_note}</p>
                                            )}
                                        </td>
                                        <td className="px-6 py-4 text-right">
                                            {payment.status === 'pending' ? (
                                                <div className="flex justify-end gap-2">
                                                    <button
                                                        type="button"
                                                        onClick={() => approve(payment)}
                                                        data-testid="payment-approve"
                                                        className="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-emerald-700"
                                                    >
                                                        Approve
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => reject(payment)}
                                                        data-testid="payment-reject"
                                                        className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-700 transition-colors hover:bg-rose-100"
                                                    >
                                                        Reject
                                                    </button>
                                                </div>
                                            ) : (
                                                <span className="text-[11px] text-slate-400">
                                                    {payment.reviewer ? `by ${payment.reviewer}` : 'reviewed'}
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                )) : (
                                    <tr>
                                        <td colSpan={6} className="px-6 py-12 text-center text-xs text-slate-400">
                                            Nothing to verify.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                {payments?.links?.length > 3 && (
                    <div className="flex flex-wrap gap-1">
                        {payments.links.map((link, index) => (
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
                    Approving creates a real <strong>Deposit</strong> for that member, so their dashboard, the
                    roster and every report update together.
                </InfoHint>
            </div>
        </AuthenticatedLayout>
    );
}