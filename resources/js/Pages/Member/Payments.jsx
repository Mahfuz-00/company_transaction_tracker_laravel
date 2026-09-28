import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageHint, InfoHint } from '@/Components/Help/HelpHint';
import Field from '@/Components/UI/Field';
import { Spinner } from '@/Components/UI/Loading';
import { Head, useForm, usePage } from '@inertiajs/react';

/**
 * MEMBER — MAKE A PAYMENT.
 *
 * A member tops up their own meal account. The safety model is stated on screen:
 * submitting a payment does NOT credit the balance. It is recorded as PENDING
 * until a manager verifies it, and only then does a real deposit appear.
 *
 * That is deliberately different from a manager recording a deposit, which moves
 * the balance immediately - because a manager has the money in hand, whereas a
 * member is CLAIMING to have paid.
 */
export default function Payments({ hasMemberRecord = true, member = {}, payments, methods = [], pendingTotal = 0 }) {
    const { flash } = usePage().props;

    const [showForm, setShowForm] = useState(false);

    const { data, setData, post, processing, errors, reset, recentlySuccessful } = useForm({
        amount: '',
        method: methods[0]?.value || 'cash',
        payer_reference: '',
        note: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('member.payments.store'), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setShowForm(false);
            },
        });
    };

    if (!hasMemberRecord) {
        return (
            <AuthenticatedLayout
                header={<p className="text-xs font-medium text-slate-500">Your account is not linked to a member record yet.</p>}
            >
                <Head title="Make a Payment" />
                <div className="rounded-2xl border border-slate-200 bg-white p-10 text-center shadow-sm">
                    <p className="text-sm font-semibold text-slate-700">Your account is not linked yet</p>
                    <p className="mx-auto mt-2 max-w-md text-sm text-slate-500">
                        Ask your meal manager to link your account, then reload this page.
                    </p>
                </div>
            </AuthenticatedLayout>
        );
    }

    const rows = payments?.data || [];
    const owed = (member.balance ?? 0) < 0;

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p className="text-xs font-medium text-slate-500">
                            Top up your meal account. A manager verifies each payment before it counts.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={() => setShowForm((v) => !v)}
                        data-testid="member-payment-new"
                        className="inline-flex items-center gap-2 self-start rounded-lg bg-[var(--accent)] px-4 py-2 text-sm font-semibold text-white shadow-sm transition-opacity hover:opacity-90"
                    >
                        {showForm ? 'Cancel' : 'Make a payment'}
                    </button>
                </div>
            }
        >
            <Head title="Make a Payment" />

            <div className="space-y-6">
                <PageHint title="How your payment is processed">
                    Submit the amount and how you paid. It appears as{' '}
                    <strong className="font-semibold text-slate-700">pending</strong> until your meal manager
                    confirms it, and only then is it added to your balance. Keep your reference number handy.
                </PageHint>

                {flash?.success && (
                    <div role="status" data-testid="payment-flash" className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
                        {flash.error}
                    </div>
                )}

                {/* Balance summary */}
                <div className={`rounded-2xl border p-5 ${owed ? 'border-rose-200 bg-rose-50/60' : 'border-emerald-200 bg-emerald-50/60'}`}>
                    <p className="text-[11px] font-bold uppercase tracking-wider text-slate-500">
                        {owed ? 'Amount you owe' : 'Your credit balance'}
                    </p>
                    <p className={`mt-1 text-3xl font-extrabold ${owed ? 'text-rose-600' : 'text-emerald-600'}`}>
                        {Math.abs(member.balance ?? 0)}
                    </p>
                    {pendingTotal > 0 && (
                        <p className="mt-1 text-xs font-medium text-amber-600">
                            {pendingTotal} in payments awaiting verification
                        </p>
                    )}
                </div>

                {/* Payment form */}
                {showForm && (
                    <form onSubmit={submit}>
                        <div className="space-y-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-xs">
                            <h3 className="text-base font-bold text-slate-900">Payment details</h3>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    label="Amount"
                                    name="amount"
                                    type="number"
                                    required
                                    min={1}
                                    value={data.amount}
                                    error={errors.amount}
                                    placeholder="e.g. 2000"
                                    onChange={(e) => setData('amount', e.target.value)}
                                />
                                <Field
                                    label="How did you pay?"
                                    name="method"
                                    type="select"
                                    required
                                    value={data.method}
                                    error={errors.method}
                                    options={methods}
                                    onChange={(e) => setData('method', e.target.value)}
                                />
                            </div>

                            <Field
                                label="Reference / transaction ID"
                                name="payer_reference"
                                value={data.payer_reference}
                                error={errors.payer_reference}
                                placeholder="e.g. bKash TrxID"
                                hint="Optional, but it makes verification much faster."
                                onChange={(e) => setData('payer_reference', e.target.value)}
                            />

                            <Field
                                label="Note"
                                name="note"
                                type="textarea"
                                value={data.note}
                                error={errors.note}
                                placeholder="Anything your manager should know"
                                onChange={(e) => setData('note', e.target.value)}
                            />

                            <div className="flex items-center justify-end gap-3 border-t border-slate-100 pt-4">
                                {recentlySuccessful && (
                                    <span className="text-xs font-semibold text-emerald-600">Submitted.</span>
                                )}
                                <button
                                    type="submit"
                                    disabled={processing}
                                    data-testid="member-payment-submit"
                                    className="inline-flex items-center gap-2 rounded-xl bg-[var(--accent)] px-6 py-2.5 text-sm font-bold text-white shadow-sm transition-opacity hover:opacity-90 disabled:opacity-50"
                                >
                                    {processing && <Spinner className="h-4 w-4" />}
                                    {processing ? 'Submitting...' : 'Submit payment'}
                                </button>
                            </div>
                        </div>
                    </form>
                )}

                {/* History */}
                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs">
                    <div className="border-b border-slate-100 bg-slate-50/70 px-6 py-3">
                        <h3 className="text-sm font-bold text-slate-900">Your payment submissions</h3>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                <tr>
                                    <th className="px-6 py-3">Reference</th>
                                    <th className="px-6 py-3">Amount</th>
                                    <th className="px-6 py-3">Method</th>
                                    <th className="px-6 py-3">Status</th>
                                    <th className="px-6 py-3">Submitted</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {rows.length > 0 ? rows.map((payment) => (
                                    <tr key={payment.id} data-testid="member-payment-row">
                                        <td className="px-6 py-3 font-mono text-xs text-slate-600">{payment.reference}</td>
                                        <td className="px-6 py-3 text-xs font-bold text-slate-900">{payment.amount}</td>
                                        <td className="px-6 py-3 text-xs text-slate-500">{payment.method_label}</td>
                                        <td className="px-6 py-3">
                                            <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-[10px] font-bold uppercase ${payment.status_tone === 'emerald'
                                                ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                                : payment.status_tone === 'rose'
                                                    ? 'border-rose-200 bg-rose-50 text-rose-700'
                                                    : 'border-amber-200 bg-amber-50 text-amber-700'}`}>
                                                {payment.status}
                                            </span>
                                            {payment.review_note && (
                                                <p className="mt-1 max-w-[14rem] text-[10px] text-slate-400">{payment.review_note}</p>
                                            )}
                                        </td>
                                        <td className="px-6 py-3 text-[11px] text-slate-400">{payment.created_at}</td>
                                    </tr>
                                )) : (
                                    <tr>
                                        <td colSpan={5} className="px-6 py-10 text-center text-xs text-slate-400">
                                            You have not submitted any payments yet.
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
                            <a
                                key={index}
                                href={link.url || '#'}
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

                <InfoHint tone="indigo">
                    A submitted payment is a <strong>claim</strong>, not a credit. Your balance only moves when
                    your meal manager verifies it.
                </InfoHint>
            </div>
        </AuthenticatedLayout>
    );
}