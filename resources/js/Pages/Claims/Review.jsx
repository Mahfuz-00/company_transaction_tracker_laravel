import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/UI/Modal';
import Field from '@/Components/UI/Field';
import useMoney from '@/Utils/useMoney';
import { useFeedback } from '@/Components/Feedback/FeedbackProvider';
import { Head, Link, router } from '@inertiajs/react';

/**
 * Manager claim review queue.
 *
 * Approve moves the money (creates the missing deposit / meal entry, or
 * reimburses a member purchase) and updates the member's balance. Reject just
 * records the decision. Both are attributed and audited server-side.
 */

function StatusChip({ status, label }) {
    const tone = {
        pending: 'border-amber-100 bg-amber-50 text-amber-700',
        approved: 'border-emerald-100 bg-emerald-50 text-emerald-700',
        rejected: 'border-rose-100 bg-rose-50 text-rose-700',
    }[status] || 'border-slate-200 bg-slate-100 text-slate-500';

    return (
        <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold ${tone}`}>
            {label}
        </span>
    );
}

/** Does this claim move money (so an amount is adjustable)? */
const claimInvolvesMoney = (claim) =>
    claim.kind === 'expense' || claim.subject === 'deposit' || claim.amount !== null;

function KindChip({ kind, label }) {
    const tone = kind === 'expense'
        ? 'border-sky-100 bg-sky-50 text-sky-700'
        : 'border-violet-100 bg-violet-50 text-violet-700';

    return (
        <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold ${tone}`}>
            {label}
        </span>
    );
}

export default function Review({ claims, stats = {}, kinds = [], filters = {} }) {
    const money = useMoney();
    const { confirm } = useFeedback();

    const rows = claims?.data || [];

    // A single modal drives both decisions; `mode` decides which endpoint fires.
    const [decision, setDecision] = useState(null); // { claim, mode: 'approve'|'reject' }
    const [notes, setNotes] = useState('');
    const [approvedAmount, setApprovedAmount] = useState('');
    const [processing, setProcessing] = useState(false);

    const openDecision = (claim, mode) => {
        setDecision({ claim, mode });
        setNotes('');
        setApprovedAmount(claim.amount !== null ? String(claim.amount) : '');
    };

    const closeDecision = () => {
        setDecision(null);
        setNotes('');
    };

    const applyFilter = (next) => {
        router.get(route('claims.review'), { ...filters, ...next }, {
            preserveState: true, preserveScroll: true, replace: true,
        });
    };

    const submitDecision = async (e) => {
        e.preventDefault();
        if (!decision) return;

        // Confirm the money-moving direction, since approval changes balances.
        if (decision.mode === 'approve') {
            const ok = await confirm({
                title: 'Approve this claim?',
                message: 'This will create the missing record (or reimburse the member) and update their balance immediately.',
                tone: 'accent',
                confirmLabel: 'Approve & apply',
            });
            if (!ok) return;
        }

        setProcessing(true);

        const payload = { review_notes: notes };
        if (decision.mode === 'approve' && approvedAmount !== '') {
            payload.approved_amount = approvedAmount;
        }

        const onFinish = () => setProcessing(false);
        const url = decision.mode === 'approve'
            ? route('claims.approve', decision.claim.id)
            : route('claims.reject', decision.claim.id);

        router.patch(url, payload, {
            preserveScroll: true,
            onSuccess: () => { closeDecision(); onFinish(); },
            onFinish,
        });
    };

    const statusTabs = [
        { value: 'pending', label: 'Pending', count: stats.pending ?? 0, tone: 'text-amber-600' },
        { value: 'approved', label: 'Approved', count: stats.approved ?? 0, tone: 'text-emerald-600' },
        { value: 'rejected', label: 'Rejected', count: stats.rejected ?? 0, tone: 'text-rose-600' },
        { value: 'all', label: 'All', count: null, tone: 'text-slate-600' },
    ];

    const activeStatus = filters?.status || 'pending';

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <h2 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">Claim Review</h2>
                    <p className="mt-0.5 text-xs font-medium text-slate-500">
                        Approve or reject the claims your members have raised.
                    </p>
                </div>
            }
        >
            <Head title="Claim Review" />

            <div className="space-y-5">
                {/* Status tabs */}
                <div className="flex flex-wrap items-center gap-2">
                    {statusTabs.map((tab) => (
                        <button
                            key={tab.value}
                            type="button"
                            onClick={() => applyFilter({ status: tab.value })}
                            className={`inline-flex items-center gap-2 rounded-lg border px-3.5 py-2 text-xs font-semibold transition-colors ${activeStatus === tab.value
                                ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent)]'
                                : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
                                }`}
                        >
                            {tab.label}
                            {tab.count !== null && (
                                <span className={`rounded-full bg-white/70 px-1.5 text-[10px] font-bold ${tab.tone}`}>
                                    {tab.count}
                                </span>
                            )}
                        </button>
                    ))}

                    <select
                        value={filters?.kind || ''}
                        onChange={(e) => applyFilter({ kind: e.target.value })}
                        aria-label="Filter by claim type"
                        className="ml-auto rounded-lg border-slate-300 text-sm text-slate-900 focus:border-[var(--accent)] focus:ring-[var(--accent)]"
                    >
                        <option value="">All types</option>
                        {(kinds || []).map((k) => (
                            <option key={k.value} value={k.value}>{k.label}</option>
                        ))}
                    </select>
                </div>

                <div className="overflow-hidden rounded-2xl border-slate-200 bg-white shadow-sm">
                    {rows.length > 0 ? (
                        <ul className="divide-y divide-slate-100">
                            {rows.map((claim) => (
                                <li key={claim.id} className="p-5">
                                    <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="font-bold text-slate-900">{claim.title}</span>
                                                <KindChip kind={claim.kind} label={claim.kind_label} />
                                                <StatusChip status={claim.status} label={claim.status_label} />
                                            </div>

                                            <div className="mt-1 flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
                                                <span className="font-semibold text-slate-700">
                                                    {claim.student?.name || 'Unknown member'}
                                                    {claim.student?.roll ? ` (${claim.student.roll})` : ''}
                                                </span>
                                                <span>Submitted {claim.created_at}</span>
                                                {claim.entry_date && <span>For {claim.entry_date}</span>}
                                                {claim.subject_label && <span>{claim.subject_label}</span>}
                                            </div>

                                            {claim.description && (
                                                <p className="mt-2 text-sm leading-relaxed text-slate-600">{claim.description}</p>
                                            )}

                                            {claim.kind === 'dispute' && claim.breakfast + claim.lunch + claim.dinner > 0 && (
                                                <div className="mt-2 flex gap-2 text-[11px]">
                                                    <span className="rounded bg-slate-100 px-2 py-0.5 font-medium text-slate-600">
                                                        B:{claim.breakfast || 0} L:{claim.lunch || 0} D:{claim.dinner || 0}
                                                    </span>
                                                </div>
                                            )}

                                            {claim.reviewer && (
                                                <p className="mt-2 text-[11px] text-slate-400">
                                                    {claim.status === 'approved' ? 'Approved' : claim.status === 'rejected' ? 'Rejected' : 'Reviewed'} by{' '}
                                                    <strong className="font-semibold text-slate-600">{claim.reviewer}</strong>
                                                    {claim.reviewed_at ? ` on ${claim.reviewed_at}` : ''}
                                                    {claim.review_notes ? ` - "${claim.review_notes}"` : ''}
                                                </p>
                                            )}
                                        </div>

                                        <div className="flex flex-shrink-0 flex-col items-start gap-2 lg:items-end">
                                            {claim.amount !== null && (
                                                <span className="text-lg font-extrabold text-slate-900">
                                                    {money(claim.amount, false)}
                                                </span>
                                            )}

                                            {claim.status === 'pending' && (
                                                <div className="flex gap-2">
                                                    <button
                                                        type="button"
                                                        onClick={() => openDecision(claim, 'approve')}
                                                        className="rounded-lg bg-emerald-600 px-3.5 py-1.5 text-xs font-bold text-white transition-colors hover:bg-emerald-700"
                                                    >
                                                        Approve
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => openDecision(claim, 'reject')}
                                                        className="rounded-lg border-rose-200 bg-white px-3.5 py-1.5 text-xs font-bold text-rose-600 transition-colors hover:bg-rose-50"
                                                    >
                                                        Reject
                                                    </button>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <div className="py-16 text-center">
                            <p className="text-sm font-semibold text-slate-600">Nothing to review here.</p>
                            <p className="mt-1 text-xs text-slate-400">
                                Member claims will appear here as they are submitted.
                            </p>
                        </div>
                    )}

                    {claims?.links?.length > 3 && (
                        <div className="flex items-center justify-between gap-4 border-t border-slate-100 px-6 py-4">
                            <p className="text-xs text-slate-500">
                                Showing <strong>{claims.from}</strong>–<strong>{claims.to}</strong> of{' '}
                                <strong>{claims.total}</strong>
                            </p>
                            <div className="flex flex-wrap gap-1">
                                {claims.links.map((link, index) => (
                                    <Link
                                        key={index}
                                        href={link.url || '#'}
                                        preserveScroll
                                        className={`rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors ${link.active
                                            ? 'border-[var(--accent)] bg-[var(--accent)] text-white'
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
            </div>

            {/* Decision modal */}
            <Modal
                open={Boolean(decision)}
                onClose={closeDecision}
                title={decision?.mode === 'approve' ? 'Approve Claim' : 'Reject Claim'}
                description={
                    decision?.mode === 'approve'
                        ? 'This applies the claim: the missing record is created (or the member is reimbursed) and their balance updates.'
                        : 'The claim is closed with no change to any balance.'
                }
                footer={
                    <>
                        <button
                            type="button"
                            onClick={closeDecision}
                            className="rounded-lg border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-100"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            form="decision-form"
                            disabled={processing}
                            className={`inline-flex items-center gap-2 rounded-lg px-5 py-2 text-sm font-bold text-white shadow-sm transition-colors disabled:opacity-50 ${decision?.mode === 'approve' ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-rose-600 hover:bg-rose-700'
                                }`}
                        >
                            {processing ? 'Saving...' : decision?.mode === 'approve' ? 'Approve & Apply' : 'Reject Claim'}
                        </button>
                    </>
                }
            >
                {decision && (
                    <form id="decision-form" onSubmit={submitDecision} className="space-y-4">
                        <div className="rounded-lg border-slate-200 bg-slate-50 p-4 text-sm">
                            <div className="font-semibold text-slate-800">{decision.claim.title}</div>
                            <div className="mt-0.5 text-xs text-slate-500">
                                {decision.claim.student?.name} · {decision.claim.kind_label}
                                {decision.claim.amount !== null ? ` · claimed ${money(decision.claim.amount, false)}` : ''}
                            </div>
                        </div>

                        {/* A manager may approve a different amount (partial). This
                            only makes sense for money claims - a meal dispute has no
                            amount to adjust. */}
                        {decision.mode === 'approve' && claimInvolvesMoney(decision.claim) && (
                            <Field
                                label="Amount to apply"
                                name="approved_amount"
                                type="number"
                                step="0.01"
                                min="0.01"
                                value={approvedAmount}
                                placeholder="Adjust if approving a different amount"
                                hint="Defaults to the claimed amount. Change it for a partial approval."
                                onChange={(e) => setApprovedAmount(e.target.value)}
                            />
                        )}

                        <Field
                            label="Note to the member"
                            name="review_notes"
                            type="textarea"
                            value={notes}
                            placeholder={decision.mode === 'approve' ? 'Optional note recorded with the approval...' : 'Explain why this claim is being rejected...'}
                            onChange={(e) => setNotes(e.target.value)}
                        />
                    </form>
                )}
            </Modal>
        </AuthenticatedLayout>
    );
}
