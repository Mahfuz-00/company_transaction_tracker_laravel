import React, { useMemo, useState } from 'react';
import MealsLayout from '@/Layouts/MealsLayout';
import Modal from '@/Components/UI/Modal';
import Field from '@/Components/UI/Field';
import useCan from '@/Utils/can';
import useMoney from '@/Utils/useMoney';
import useTerminology from '@/Utils/useTerminology';
import { Spinner } from '@/Components/UI/Loading';
import { useFeedback } from '@/Components/Feedback/FeedbackProvider';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';

const EMPTY_FORM = {
    student_id: '',
    amount: '',
    reason: 'withdrawal',
    payment_method: 'Cash',
    notes: '',
};

const initials = (name) =>
    name
        ? name
            .trim()
            .split(/\s+/)
            .map((part) => part[0])
            .slice(0, 2)
            .join('')
            .toUpperCase()
        : '?';

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
 * Meal module - Balance REFUNDS list.
 *
 * The mirror of Deposits: money paid back OUT of a member's meal balance when
 * they stop meals or withdraw funds. Every refund is ALSO written to the
 * transaction ledger as a cash-out, and reduces the member's balance. A Meal
 * Manager sees only their assigned members (scoped in the controller).
 *
 * PROPS (from RefundController::index)
 *  - refunds: paginator of refund rows (nested `student`, `recorder`, `reverser`).
 *  - members: roster with each member's withdrawable `balance`.
 *  - reasons: [{ value, label }] refund reasons.
 *  - costPerMeal: current per-meal rate (for the balance hint).
 *  - filteredTotal / activeTotal / reversedTotal: sums for the active filter.
 *  - filters: { search, student, reason, from, to } echo of the query.
 */
export default function Index({ refunds, members, reasons, costPerMeal, filteredTotal, activeTotal, reversedTotal, filters }) {
    const { can } = useCan();
    const { t, tTitle } = useTerminology();
    const { flash } = usePage().props;
    const money = useMoney();
    const { confirm } = useFeedback();
    const canRecord = can('meals.deposit');

    const [modalOpen, setModalOpen] = useState(false);
    const [search, setSearch] = useState(filters?.search || '');

    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({ ...EMPTY_FORM });

    const rows = refunds?.data || [];

    const studentOptions = useMemo(
        () => [
            { value: '', label: `— Select a ${t('member', 'member').toLowerCase()} —` },
            ...(members || []).map((member) => ({
                value: String(member.id),
                label: `${member.name}${member.roll ? ` (${member.roll})` : ''} — credit ${money(member.balance, false)}`,
            })),
        ],
        [members, money, t]
    );

    // The member currently chosen in the modal, so we can show their available
    // credit and warn when the amount exceeds it.
    const selectedMember = useMemo(
        () => (members || []).find((member) => String(member.id) === String(data.student_id)),
        [members, data.student_id]
    );

    const exceedsBalance =
        selectedMember && Number(data.amount) > 0 && Number(data.amount) > Number(selectedMember.balance) + 0.001;

    const openModal = () => {
        clearErrors();
        reset();
        setData({ ...EMPTY_FORM });
        setModalOpen(true);
    };

    const closeModal = () => {
        setModalOpen(false);
        reset();
    };

    const submit = (event) => {
        event.preventDefault();
        post(route('meals.refunds.store'), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        });
    };

    const reverse = async (refund) => {
        const ok = await confirm({
            title: 'Reverse this refund?',
            message: `A matching cash-in will be posted and ${refund.student?.name || 'the member'}'s balance will rise by ${money(refund.amount, false)}. The record is kept for the audit trail.`,
            tone: 'danger',
            confirmLabel: 'Reverse refund',
        });
        if (!ok) return;

        router.patch(route('meals.refunds.reverse', refund.id), {}, { preserveScroll: true });
    };

    const applyFilters = (next) => {
        router.get(
            route('meals.refunds.index'),
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true, replace: true }
        );
    };

    const hasFilters =
        Boolean(filters?.search) ||
        Boolean(filters?.student) ||
        Boolean(filters?.reason) ||
        Boolean(filters?.from) ||
        Boolean(filters?.to);

    return (
        <MealsLayout
            title="Refunds"
            description="Money paid back out of a member's meal balance when they stop meals or withdraw funds. Every refund is also recorded as a cash-out transaction."
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
                        Record Refund
                    </button>
                )
            }
        >
            <Head title="Refunds" />

            <Flash success={flash?.success} error={flash?.error} />

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div className="rounded-xl border-slate-200 bg-white p-4 shadow-sm">
                    <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        {hasFilters ? 'Total for current filter' : 'Total refunded (all time)'}
                    </div>
                    <div className="mt-1 text-2xl font-bold text-rose-600">
                        {money(filteredTotal, false)}
                    </div>
                </div>
                <div className="rounded-xl border-slate-200 bg-white p-4 shadow-sm">
                    <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        Active Refunds
                    </div>
                    <div className="mt-1 text-2xl font-bold text-slate-800">
                        {money(activeTotal ?? 0, false)}
                    </div>
                    <div className="mt-0.5 text-[11px] text-slate-400">Currently reducing balances</div>
                </div>
                <div className="rounded-xl border-slate-200 bg-white p-4 shadow-sm">
                    <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        Reversed Refunds
                    </div>
                    <div className="mt-1 text-2xl font-bold text-slate-400">
                        {money(reversedTotal ?? 0, false)}
                    </div>
                    <div className="mt-0.5 text-[11px] text-slate-400">Corrected - no longer counted</div>
                </div>
            </div>

            <div className="overflow-hidden rounded-xl border-slate-200 bg-white shadow-sm">
                <div className="flex flex-col gap-3 border-b border-slate-100 px-6 py-4 sm:flex-row sm:items-center">
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            applyFilters({ search });
                        }}
                        className="relative max-w-xs flex-1"
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
                            placeholder={`Search ${t('member', 'member').toLowerCase()} or note...`}
                            className="w-full rounded-lg border-slate-300 py-2 pl-9 pr-3 text-sm outline-none transition-all focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                        />
                    </form>

                    <select
                        value={filters?.student || ''}
                        onChange={(event) => applyFilters({ student: event.target.value })}
                        className="rounded-lg border-slate-300 text-sm text-slate-900 focus:border-indigo-500 focus:ring-indigo-500"
                    >
                        <option value="">All {t('members', 'members')}</option>
                        {(members || []).map((member) => (
                            <option key={member.id} value={member.id}>{member.name}</option>
                        ))}
                    </select>

                    <select
                        value={filters?.reason || ''}
                        onChange={(event) => applyFilters({ reason: event.target.value })}
                        className="rounded-lg border-slate-300 text-sm text-slate-900 focus:border-indigo-500 focus:ring-indigo-500"
                    >
                        <option value="">All reasons</option>
                        {(reasons || []).map((r) => (
                            <option key={r.value} value={r.value}>{r.label}</option>
                        ))}
                    </select>

                    <input
                        type="date"
                        value={filters?.from || ''}
                        onChange={(event) => applyFilters({ from: event.target.value })}
                        className="rounded-lg border-slate-300 text-sm text-slate-900 focus:border-indigo-500 focus:ring-indigo-500"
                        aria-label="From date"
                    />

                    <input
                        type="date"
                        value={filters?.to || ''}
                        onChange={(event) => applyFilters({ to: event.target.value })}
                        className="rounded-lg border-slate-300 text-sm text-slate-900 focus:border-indigo-500 focus:ring-indigo-500"
                        aria-label="To date"
                    />

                    {hasFilters && (
                        <button
                            type="button"
                            onClick={() => {
                                setSearch('');
                                router.get(route('meals.refunds.index'), {}, { replace: true });
                            }}
                            className="text-xs font-semibold text-indigo-600 transition-colors hover:text-indigo-800"
                        >
                            Clear
                        </button>
                    )}
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full border-collapse text-left">
                        <thead>
                            <tr className="border-b border-slate-100 bg-slate-50 text-xs font-semibold uppercase tracking-wider text-slate-400">
                                <th className="px-6 py-3">{t('member', 'Member')}</th>
                                <th className="px-6 py-3">Amount</th>
                                <th className="px-6 py-3">Reason</th>
                                <th className="px-6 py-3">Method</th>
                                <th className="px-6 py-3">Recorded By</th>
                                <th className="px-6 py-3">Date</th>
                                <th className="px-6 py-3">Notes</th>
                                {canRecord && <th className="px-6 py-3 text-right">Actions</th>}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 text-sm">
                            {rows.length > 0 ? (
                                rows.map((refund) => (
                                    <tr key={refund.id} className={`transition-colors hover:bg-slate-50/60 ${refund.reversed_at ? 'opacity-60' : ''}`}>
                                        <td className="px-6 py-4">
                                            <div className="flex items-center gap-3">
                                                <div className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-rose-600 text-[10px] font-bold text-white">
                                                    {initials(refund.student?.name)}
                                                </div>
                                                <div className="min-w-0">
                                                    <Link
                                                        href={route('meals.students.show', refund.student_id)}
                                                        className="font-semibold text-slate-900 hover:text-indigo-600"
                                                    >
                                                        {refund.student?.name || 'Unknown'}
                                                    </Link>
                                                    {refund.student?.roll && (
                                                        <div className="text-xs text-slate-400">{refund.student.roll}</div>
                                                    )}
                                                </div>
                                            </div>
                                        </td>
                                        <td className={`px-6 py-4 font-bold ${refund.reversed_at ? 'text-slate-400 line-through' : 'text-rose-600'}`}>
                                            -{money(refund.amount, false)}
                                            {refund.reversed_at && (
                                                <span className="ml-2 inline-flex items-center rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-bold uppercase text-rose-600 no-underline">
                                                    Reversed
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-6 py-4">
                                            <span className="inline-flex items-center rounded-full border border-rose-100 bg-rose-50 px-2.5 py-0.5 text-[11px] font-semibold text-rose-700">
                                                {(reasons || []).find((r) => r.value === refund.reason)?.label || refund.reason}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4 text-slate-600">{refund.payment_method || 'Cash'}</td>
                                        <td className="px-6 py-4 text-slate-500">{refund.recorder?.name || '—'}</td>
                                        <td className="px-6 py-4 text-slate-500">
                                            {new Date(refund.created_at).toLocaleDateString()}
                                        </td>
                                        <td className="max-w-xs px-6 py-4 text-xs text-slate-500">
                                            {refund.notes || <span className="text-slate-300">—</span>}
                                        </td>
                                        {canRecord && (
                                            <td className="whitespace-nowrap px-6 py-4 text-right">
                                                {!refund.reversed_at ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => reverse(refund)}
                                                        className="font-medium text-rose-500 transition-colors hover:text-rose-700"
                                                    >
                                                        Reverse
                                                    </button>
                                                ) : (
                                                    <span className="text-xs italic text-slate-400">
                                                        Reversed {refund.reverser?.name ? `by ${refund.reverser.name}` : ''}
                                                    </span>
                                                )}
                                            </td>
                                        )}
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td colSpan={canRecord ? 8 : 7} className="py-14 text-center">
                                        <p className="text-sm font-semibold text-slate-600">
                                            {hasFilters ? 'No refunds match these filters.' : 'No refunds recorded yet.'}
                                        </p>
                                        <p className="mt-1 text-xs text-slate-400">
                                            Record a refund when a {t('member', 'member').toLowerCase()} stops meals or withdraws their balance.
                                        </p>
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {refunds?.links?.length > 3 && (
                    <div className="flex items-center justify-between gap-4 border-t border-slate-100 px-6 py-4">
                        <p className="text-xs text-slate-500">
                            Showing <strong>{refunds.from}</strong>–<strong>{refunds.to}</strong> of{' '}
                            <strong>{refunds.total}</strong>
                        </p>
                        <div className="flex flex-wrap gap-1">
                            {refunds.links.map((link, index) => (
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

            <Modal
                open={modalOpen}
                onClose={closeModal}
                title="Record Refund"
                description="This pays money back out of the member's balance and posts a matching cash-out transaction."
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
                            form="refund-form"
                            disabled={processing || exceedsBalance}
                            className="inline-flex items-center gap-2 rounded-lg bg-rose-600 px-5 py-2 text-sm font-medium text-white shadow-sm transition-all hover:bg-rose-700 active:bg-rose-800 disabled:opacity-50"
                        >
                            {processing && <Spinner className="h-4 w-4" />}
                            {processing ? 'Saving...' : 'Record Refund'}
                        </button>
                    </>
                }
            >
                <form id="refund-form" onSubmit={submit} className="space-y-4">
                    <Field
                        label={tTitle('member', 'Member')}
                        name="student_id"
                        type="select"
                        required
                        value={data.student_id}
                        error={errors.student_id}
                        options={studentOptions}
                        onChange={(event) => setData('student_id', event.target.value)}
                    />

                    {selectedMember && (
                        <div className={`rounded-lg border p-3 text-xs ${exceedsBalance ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-slate-200 bg-slate-50 text-slate-500'}`}>
                            Available credit: <strong>{money(selectedMember.balance, false)}</strong>
                            {exceedsBalance && ' — the refund cannot exceed this.'}
                        </div>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Amount"
                            name="amount"
                            type="number"
                            required
                            step="0.01"
                            min="0.01"
                            max={selectedMember ? selectedMember.balance : undefined}
                            value={data.amount}
                            error={errors.amount}
                            placeholder={`e.g. ${costPerMeal > 0 ? Math.max(0, Math.round(selectedMember?.balance || 0)) : 500}`}
                            onChange={(event) => setData('amount', event.target.value)}
                        />

                        <Field
                            label="Payment Method"
                            name="payment_method"
                            type="select"
                            value={data.payment_method}
                            error={errors.payment_method}
                            options={[
                                { value: 'Cash', label: 'Cash' },
                                { value: 'bKash', label: 'bKash' },
                                { value: 'Nagad', label: 'Nagad' },
                                { value: 'Bank Transfer', label: 'Bank Transfer' },
                            ]}
                            onChange={(event) => setData('payment_method', event.target.value)}
                        />
                    </div>

                    <Field
                        label="Reason"
                        name="reason"
                        type="select"
                        value={data.reason}
                        error={errors.reason}
                        options={(reasons || []).map((r) => ({ value: r.value, label: r.label }))}
                        onChange={(event) => setData('reason', event.target.value)}
                    />

                    <Field
                        label="Notes"
                        name="notes"
                        type="textarea"
                        value={data.notes}
                        onChange={(event) => setData('notes', event.target.value)}
                        error={errors.notes}
                        placeholder="Optional note about this refund..."
                    />
                </form>
            </Modal>
        </MealsLayout>
    );
}
