import React, { useMemo, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/UI/Modal';
import Field from '@/Components/UI/Field';
import useMoney from '@/Utils/useMoney';
import useTerminology from '@/Utils/useTerminology';
import { Head, Link, useForm } from '@inertiajs/react';

/**
 * Member claims & disputes.
 *
 * A member can raise two kinds of claim:
 *   - "Missing entry / dispute" : a deposit or meal entry the manager missed.
 *   - "I bought something"      : an out-of-pocket purchase for the institution.
 *
 * Nothing here moves money - a manager must approve the claim first.
 */

const EMPTY_FORM = {
    kind: 'dispute',
    subject: 'deposit',
    amount: '',
    title: '',
    description: '',
    claim_date: '',
    payment_method: 'Cash',
    entry_date: '',
    breakfast: 0,
    lunch: 0,
    dinner: 0,
};

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

export default function Index({ hasMemberRecord = true, claims, kinds = [], subjects = [] }) {
    const money = useMoney();
    const { t } = useTerminology();

    const [modalOpen, setModalOpen] = useState(false);

    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({ ...EMPTY_FORM });

    const rows = claims?.data || [];

    const subjectOptions = useMemo(
        () => (subjects || []).map((s) => ({ value: s.value, label: s.label })),
        [subjects]
    );

    const kindOptions = useMemo(
        () => (kinds || []).map((k) => ({ value: k.value, label: k.label })),
        [kinds]
    );

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

    const submit = (e) => {
        e.preventDefault();
        post(route('claims.store'), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        });
    };

    const isExpense = data.kind === 'expense';
    const isMealDispute = data.kind === 'dispute' && data.subject === 'meal';
    const mealCount = Number(data.breakfast || 0) + Number(data.lunch || 0) + Number(data.dinner || 0);

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">My Claims &amp; Disputes</h2>
                        <p className="mt-0.5 text-xs font-medium text-slate-500">
                            Report a missing {t('deposit', 'deposit').toLowerCase()} or meal entry, or claim an out-of-pocket purchase.
                        </p>
                    </div>
                    {hasMemberRecord && (
                        <button
                            type="button"
                            onClick={openModal}
                            className="inline-flex items-center gap-2 self-start rounded-lg bg-[var(--accent)] px-4 py-2 text-sm font-bold text-white shadow-sm transition-opacity hover:opacity-90"
                        >
                            <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                            </svg>
                            New Claim
                        </button>
                    )}
                </div>
            }
        >
            <Head title="My Claims" />

            {!hasMemberRecord ? (
                <div className="rounded-2xl border-slate-200 bg-white p-10 text-center shadow-sm">
                    <h3 className="text-base font-bold text-slate-800">Your account is not linked yet</h3>
                    <p className="mx-auto mt-2 max-w-md text-sm text-slate-500">
                        Claims need a {t('member', 'member').toLowerCase()} record. Ask your manager to link your account.
                    </p>
                </div>
            ) : (
                <div className="space-y-5">
                    {/* How it works */}
                    <div className="rounded-xl border-slate-200 bg-slate-50 p-4 text-xs leading-relaxed text-slate-500">
                        <strong className="font-semibold text-slate-700">How it works:</strong> submit a claim and your
                        manager reviews it. Nothing changes on your balance until it is approved - then the missing{' '}
                        entry is created (or your purchase is reimbursed) automatically.
                    </div>

                    <div className="overflow-hidden rounded-2xl border-slate-200 bg-white shadow-sm">
                        <div className="border-b border-slate-100 px-6 py-4">
                            <h3 className="text-base font-bold text-slate-900">Your claims</h3>
                            <p className="text-xs text-slate-500">Track the status of everything you have submitted</p>
                        </div>

                        {rows.length > 0 ? (
                            <ul className="divide-y divide-slate-100">
                                {rows.map((claim) => (
                                    <li key={claim.id} className="px-6 py-4">
                                        <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                            <div className="min-w-0">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="font-semibold text-slate-900">{claim.title}</span>
                                                    <KindChip kind={claim.kind} label={claim.kind_label} />
                                                    {claim.subject_label && (
                                                        <span className="text-[11px] font-medium text-slate-400">
                                                            {claim.subject_label}
                                                        </span>
                                                    )}
                                                </div>
                                                {claim.description && (
                                                    <p className="mt-1 text-xs leading-relaxed text-slate-500">{claim.description}</p>
                                                )}
                                                <div className="mt-1.5 flex-wrap gap-x-4 gap-y-1 text-[11px] text-slate-400">
                                                    <span>Submitted {claim.created_at}</span>
                                                    {claim.amount !== null && (
                                                        <span className="font-semibold text-slate-600">
                                                            {money(claim.amount, false)}
                                                        </span>
                                                    )}
                                                    {claim.entry_date && <span>For {claim.entry_date}</span>}
                                                </div>
                                                {claim.review_notes && (
                                                    <p className="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                                                        <strong className="font-semibold">Manager note:</strong> {claim.review_notes}
                                                    </p>
                                                )}
                                            </div>
                                            <div className="flex-shrink-0">
                                                <StatusChip status={claim.status} label={claim.status_label} />
                                            </div>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <div className="py-14 text-center">
                                <p className="text-sm font-semibold text-slate-600">No claims yet.</p>
                                <p className="mt-1 text-xs text-slate-400">
                                    Missing a payment or a meal? Raise a claim and it will be reviewed.
                                </p>
                                <button
                                    type="button"
                                    onClick={openModal}
                                    className="mt-4 inline-flex items-center gap-2 rounded-lg bg-[var(--accent)] px-4 py-2 text-sm font-bold text-white transition-opacity hover:opacity-90"
                                >
                                    Raise your first claim
                                </button>
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
            )}

            {/* New claim modal */}
            <Modal
                open={modalOpen}
                onClose={closeModal}
                title="Raise a Claim"
                description="Your manager will review this. Nothing changes on your balance until it is approved."
                maxWidth="max-w-2xl"
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
                            form="claim-form"
                            disabled={processing}
                            className="inline-flex items-center gap-2 rounded-lg bg-[var(--accent)] px-5 py-2 text-sm font-bold text-white shadow-sm transition-opacity hover:opacity-90 disabled:opacity-50"
                        >
                            {processing ? 'Submitting...' : 'Submit Claim'}
                        </button>
                    </>
                }
            >
                <form id="claim-form" onSubmit={submit} className="space-y-4">
                    <Field
                        label="What is this about?"
                        name="kind"
                        type="select"
                        required
                        value={data.kind}
                        error={errors.kind}
                        options={kindOptions}
                        onChange={(e) => setData('kind', e.target.value)}
                    />

                    {/* Dispute: what kind of entry is missing. */}
                    {data.kind === 'dispute' && (
                        <Field
                            label="Missing what?"
                            name="subject"
                            type="select"
                            required
                            value={data.subject}
                            error={errors.subject}
                            options={subjectOptions}
                            onChange={(e) => setData('subject', e.target.value)}
                        />
                    )}

                    <Field
                        label={isExpense ? 'What did you buy?' : 'Summary'}
                        name="title"
                        required
                        value={data.title}
                        error={errors.title}
                        placeholder={isExpense ? 'e.g. Bought rice and oil for the kitchen' : 'e.g. My deposit on 5 Sep is missing'}
                        onChange={(e) => setData('title', e.target.value)}
                    />

                    {/* Meal dispute: which day and which meals were missed. */}
                    {isMealDispute && (
                        <>
                            <Field
                                label="Date the meal was missed"
                                name="entry_date"
                                type="date"
                                required
                                value={data.entry_date}
                                error={errors.entry_date}
                                onChange={(e) => setData('entry_date', e.target.value)}
                            />

                            <div className="rounded-lg border-slate-200 bg-slate-50 p-4">
                                <p className="mb-3 text-xs font-bold uppercase tracking-wider text-slate-500">
                                    Meals to add back
                                </p>
                                <div className="grid grid-cols-3 gap-3">
                                    {[
                                        { key: 'breakfast', label: 'Breakfast' },
                                        { key: 'lunch', label: 'Lunch' },
                                        { key: 'dinner', label: 'Dinner' },
                                    ].map((meal) => (
                                        <Field
                                            key={meal.key}
                                            label={meal.label}
                                            name={meal.key}
                                            type="number"
                                            min={0}
                                            max={10}
                                            value={data[meal.key]}
                                            error={errors[meal.key]}
                                            onChange={(e) => setData(meal.key, Number(e.target.value))}
                                        />
                                    ))}
                                </div>
                                <p className="mt-2 text-[11px] text-slate-400">
                                    {mealCount} meal{mealCount === 1 ? '' : 's'} will be requested.
                                </p>
                            </div>
                        </>
                    )}

                    {/* Money is relevant for a deposit dispute or an expense. */}
                    {(isExpense || data.subject === 'deposit') && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label={isExpense ? 'Amount you spent' : 'Amount missing'}
                                name="amount"
                                type="number"
                                step="0.01"
                                min="0.01"
                                required
                                value={data.amount}
                                error={errors.amount}
                                placeholder="0.00"
                                onChange={(e) => setData('amount', e.target.value)}
                            />
                            <Field
                                label="Payment method"
                                name="payment_method"
                                type="select"
                                value={data.payment_method}
                                error={errors.payment_method}
                                options={[
                                    { value: 'Cash', label: 'Cash' },
                                    { value: 'bKash', label: 'bKash' },
                                    { value: 'Nagad', label: 'Nagad' },
                                    { value: 'Bank Transfer', label: 'Bank Transfer' },
                                    { value: 'Card', label: 'Card' },
                                ]}
                                onChange={(e) => setData('payment_method', e.target.value)}
                            />
                        </div>
                    )}

                    <Field
                        label="Date it happened"
                        name="claim_date"
                        type="date"
                        value={data.claim_date}
                        error={errors.claim_date}
                        onChange={(e) => setData('claim_date', e.target.value)}
                    />

                    <Field
                        label="Details"
                        name="description"
                        type="textarea"
                        value={data.description}
                        error={errors.description}
                        placeholder={isExpense
                            ? 'Where you bought it, the receipt reference, why it was for the institution...'
                            : 'Explain what happened so your manager can verify it...'}
                        onChange={(e) => setData('description', e.target.value)}
                    />
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
