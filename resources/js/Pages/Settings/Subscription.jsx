import React, { useState } from 'react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { PageHint, InfoHint } from '@/Components/Help/HelpHint';
import Field from '@/Components/UI/Field';
import { Spinner } from '@/Components/UI/Loading';
import { Head, useForm, usePage } from '@inertiajs/react';

/**
 * INSTITUTION SUBSCRIPTION & BILLING.
 *
 * Where an Institution Admin sees their plan, what is owed, and pays the platform
 * fee directly - without emailing the platform owner.
 *
 * THE SAFETY CONTRACT, stated on screen: submitting a payment does NOT change the
 * subscription. It sits as PENDING until the platform team verifies it, and only
 * then is the paid-up period extended. Saying so plainly prevents the obvious
 * misunderstanding ("I paid, why is it still showing overdue?").
 */
export default function Subscription({ institution = {}, payments = [], methods = [], pendingTotal = 0, canSubmit = false }) {
    const { flash } = usePage().props;

    const { data, setData, post, processing, errors, reset, recentlySuccessful } = useForm({
        amount: institution.subscription_amount || '',
        currency_code: institution.currency_code || 'USD',
        period_months: 1,
        method: 'bank',
        paid_on: new Date().toISOString().slice(0, 10),
        payer_reference: '',
        note: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('settings.subscription.store'), {
            preserveScroll: true,
            onSuccess: () => reset('payer_reference', 'note'),
        });
    };

    return (
        <SettingsLayout title="Subscription & Billing">
            <Head title="Subscription & Billing" />

            <div className="space-y-6">
                <PageHint title="How paying for your workspace works">
                    Submit the details of your payment here. It is recorded as{' '}
                    <strong className="font-semibold text-slate-700">pending</strong> until the platform team
                    verifies it - only then does your paid-up period extend. Keep your reference number: it is
                    what the team checks against.
                </PageHint>

                {flash?.success && (
                    <div role="status" data-testid="subscription-flash" className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
                        {flash.error}
                    </div>
                )}

                {/* ---- Current plan ---- */}
                <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-xs">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                Current subscription
                            </p>
                            <h3 className="mt-1 text-lg font-bold text-slate-900">
                                {institution.name}
                            </h3>
                            <p className="mt-0.5 text-xs text-slate-500">
                                Plan: <strong className="font-semibold text-slate-700">
                                    {institution.subscription_plan || (institution.is_on_trial ? 'Trial' : 'Not set')}
                                </strong>
                            </p>
                        </div>

                        <div className="flex flex-col items-start gap-2 sm:items-end">
                            <span
                                data-testid="subscription-status"
                                className={`inline-flex items-center rounded-full border px-3 py-1 text-xs font-bold ${institution.subscription_tone === 'emerald'
                                    ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                    : institution.subscription_tone === 'rose'
                                        ? 'border-rose-200 bg-rose-50 text-rose-700'
                                        : 'border-amber-200 bg-amber-50 text-amber-700'}`}
                            >
                                {institution.subscription_label}
                            </span>

                            {institution.is_on_trial && (
                                <span className="text-[11px] font-medium text-amber-600">
                                    {institution.trial_days_left} day(s) left in trial
                                    {institution.trial_ends_at ? ` · ends ${institution.trial_ends_at}` : ''}
                                </span>
                            )}

                            {institution.subscription_amount > 0 && (
                                <span className="text-sm font-bold text-slate-900">
                                    {institution.subscription_amount} {institution.currency_code || ''}
                                    <span className="text-xs font-medium text-slate-400"> / month</span>
                                </span>
                            )}
                        </div>
                    </div>
                </div>

                {pendingTotal > 0 && (
                    <InfoHint tone="amber">
                        You have <strong>{pendingTotal}</strong> in submitted payment(s) awaiting verification.
                        Your subscription updates as soon as the platform team confirms them.
                    </InfoHint>
                )}

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    {/* ---- Pay form ---- */}
                    <form onSubmit={submit} className="lg:col-span-2">
                        <div className="space-y-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-xs">
                            <div>
                                <h3 className="text-base font-bold text-slate-900">Submit a payment</h3>
                                <p className="mt-0.5 text-xs text-slate-500">
                                    Record the payment you have made (or are about to make) to the platform.
                                </p>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    label="Amount"
                                    name="amount"
                                    type="number"
                                    required
                                    value={data.amount}
                                    error={errors.amount}
                                    placeholder="e.g. 5000"
                                    onChange={(e) => setData('amount', e.target.value)}
                                />
                                <Field
                                    label="Currency"
                                    name="currency_code"
                                    required
                                    value={data.currency_code}
                                    error={errors.currency_code}
                                    placeholder="e.g. BDT"
                                    hint="The currency you actually paid in."
                                    onChange={(e) => setData('currency_code', e.target.value.toUpperCase())}
                                />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    label="Covers how many months?"
                                    name="period_months"
                                    type="number"
                                    required
                                    min={1}
                                    max={36}
                                    value={data.period_months}
                                    error={errors.period_months}
                                    onChange={(e) => setData('period_months', e.target.value)}
                                />
                                <Field
                                    label="Payment method"
                                    name="method"
                                    type="select"
                                    required
                                    value={data.method}
                                    error={errors.method}
                                    options={methods}
                                    onChange={(e) => setData('method', e.target.value)}
                                />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    label="Date paid"
                                    name="paid_on"
                                    type="date"
                                    required
                                    value={data.paid_on}
                                    error={errors.paid_on}
                                    onChange={(e) => setData('paid_on', e.target.value)}
                                />
                                <Field
                                    label="Transaction reference"
                                    name="payer_reference"
                                    value={data.payer_reference}
                                    error={errors.payer_reference}
                                    placeholder="Bank / gateway reference"
                                    hint="This is what the platform team verifies against."
                                    onChange={(e) => setData('payer_reference', e.target.value)}
                                />
                            </div>

                            <Field
                                label="Note"
                                name="note"
                                type="textarea"
                                value={data.note}
                                error={errors.note}
                                placeholder="Anything the platform team should know"
                                onChange={(e) => setData('note', e.target.value)}
                            />

                            <div className="flex items-center justify-end gap-3 border-t border-slate-100 pt-4">
                                {recentlySuccessful && (
                                    <span className="text-xs font-semibold text-emerald-600">Submitted.</span>
                                )}
                                <button
                                    type="submit"
                                    disabled={processing || !canSubmit}
                                    data-testid="subscription-submit"
                                    className="inline-flex items-center gap-2 rounded-xl bg-[var(--accent)] px-6 py-2.5 text-sm font-bold text-white shadow-sm transition-opacity hover:opacity-90 disabled:opacity-50"
                                >
                                    {processing && <Spinner className="h-4 w-4" />}
                                    {processing ? 'Submitting...' : 'Submit payment'}
                                </button>
                            </div>

                            {!canSubmit && (
                                <InfoHint tone="amber">
                                    Only an Institution Admin can submit a subscription payment.
                                </InfoHint>
                            )}
                        </div>
                    </form>

                    {/* ---- Guidance ---- */}
                    <div className="rounded-2xl border border-slate-200 bg-slate-50 p-6 text-xs text-slate-500 shadow-xs">
                        <p className="font-semibold text-slate-700">What happens next</p>
                        <ol className="mt-2 list-decimal space-y-1.5 pl-4">
                            <li>Your payment is recorded as <strong>pending</strong>.</li>
                            <li>The platform team checks it against your reference.</li>
                            <li>On approval, your paid-up period extends immediately.</li>
                            <li>You will see the new end date on this page.</li>
                        </ol>
                    </div>
                </div>

                {/* ---- Payment history ---- */}
                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs">
                    <div className="border-b border-slate-100 bg-slate-50/70 px-6 py-3">
                        <h3 className="text-sm font-bold text-slate-900">Payment history</h3>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                <tr>
                                    <th className="px-6 py-3">Reference</th>
                                    <th className="px-6 py-3">Amount</th>
                                    <th className="px-6 py-3">Period</th>
                                    <th className="px-6 py-3">Status</th>
                                    <th className="px-6 py-3">Submitted</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {payments.length > 0 ? payments.map((payment) => (
                                    <tr key={payment.id} data-testid="subscription-payment-row">
                                        <td className="px-6 py-3 font-mono text-xs text-slate-600">{payment.reference}</td>
                                        <td className="px-6 py-3 text-xs font-semibold text-slate-800">
                                            {payment.amount} {payment.currency_code}
                                        </td>
                                        <td className="px-6 py-3 text-xs text-slate-500">
                                            {payment.period_months} month(s)
                                            {payment.covers_to ? ` · to ${payment.covers_to}` : ''}
                                        </td>
                                        <td className="px-6 py-3">
                                            <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-[10px] font-bold uppercase ${payment.status_tone === 'emerald'
                                                ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                                : payment.status_tone === 'rose'
                                                    ? 'border-rose-200 bg-rose-50 text-rose-700'
                                                    : 'border-amber-200 bg-amber-50 text-amber-700'}`}>
                                                {payment.status}
                                            </span>
                                            {payment.review_note && (
                                                <p className="mt-1 text-[10px] text-slate-400">{payment.review_note}</p>
                                            )}
                                        </td>
                                        <td className="px-6 py-3 text-[11px] text-slate-400">{payment.created_at}</td>
                                    </tr>
                                )) : (
                                    <tr>
                                        <td colSpan={5} className="px-6 py-10 text-center text-xs text-slate-400">
                                            No payments submitted yet.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    );
}