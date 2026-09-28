import React, { useState } from 'react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { PageHint, InfoHint } from '@/Components/Help/HelpHint';
import Field from '@/Components/UI/Field';
import { Spinner } from '@/Components/UI/Loading';
import { useFeedback } from '@/Components/Feedback/FeedbackProvider';
import { Head, router, useForm, usePage } from '@inertiajs/react';

/**
 * MULTI-CURRENCY & FX RATE SNAPSHOTS (SSA only).
 *
 * Institutions bill in their own currency. To report platform revenue across them
 * you need a rate, and to report it HONESTLY you need to know which rate applied
 * on which day. Hence SNAPSHOTS rather than one live number.
 *
 * The roll-up below converts each institution's subscription into the reporting
 * currency and, crucially, NAMES the ones it could not convert rather than quietly
 * leaving them out of the total.
 */
export default function CurrencyRates({ rates, reportingCurrency = 'USD', activeCurrencies = [], rollup = {} }) {
    const { flash } = usePage().props;
    const { confirm } = useFeedback();

    const [showForm, setShowForm] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        base_code: activeCurrencies[0] || 'BDT',
        quote_code: reportingCurrency,
        rate: '',
        effective_on: new Date().toISOString().slice(0, 10),
        source: 'manual',
    });

    const rows = rates?.data || [];

    const submit = (e) => {
        e.preventDefault();
        post(route('ssa.currencies.store'), {
            preserveScroll: true,
            onSuccess: () => { reset('rate'); setShowForm(false); },
        });
    };

    const remove = async (rate) => {
        const ok = await confirm({
            title: `Remove the ${rate.base_code} → ${rate.quote_code} snapshot?`,
            message: `The rate recorded on ${rate.effective_on} will no longer be used for conversions.`,
            tone: 'warning',
            confirmLabel: 'Remove snapshot',
        });
        if (!ok) return;

        router.delete(route('ssa.currencies.destroy', rate.id), { preserveScroll: true });
    };

    // A currency option list for the pickers: the ones actually in use, plus the
    // reporting currency, so the form is never empty on a fresh install.
    const currencyOptions = Array.from(new Set([...activeCurrencies, reportingCurrency]))
        .map((code) => ({ value: code, label: code }));

    return (
        <SettingsLayout title="Currency & FX Rates">
            <Head title="Currency & FX Rates" />

            <div className="space-y-6">
                <PageHint title="Why rate snapshots, not one live rate">
                    Each save records a rate <strong className="font-semibold text-slate-700">as of a date</strong>.
                    Conversions always use the most recent snapshot on or before the date in question - so a figure
                    you reported last month does not change when today's rate moves.
                </PageHint>

                {flash?.success && (
                    <div role="status" data-testid="fx-flash" className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
                        {flash.error}
                    </div>
                )}

                {/* ---- Cross-institution revenue roll-up ---- */}
                <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-xs">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 className="text-base font-bold text-slate-900">
                                Platform revenue in {reportingCurrency}
                            </h3>
                            <p className="mt-0.5 text-xs text-slate-500">
                                Every institution's subscription, converted at the latest snapshot.
                            </p>
                        </div>
                        <div className="text-right">
                            <p data-testid="fx-rollup-total" className="text-2xl font-extrabold text-slate-900">
                                {rollup.total ?? 0} <span className="text-sm font-semibold text-slate-400">{reportingCurrency}</span>
                            </p>
                            <p className="text-[11px] text-slate-400">
                                {rollup.converted_count ?? 0} converted
                                {rollup.unconverted_count > 0 ? ` · ${rollup.unconverted_count} missing a rate` : ''}
                            </p>
                        </div>
                    </div>

                    {/* Institutions that could NOT be converted are named, not hidden. */}
                    {rollup.unconverted?.length > 0 && (
                        <div data-testid="fx-unconverted" className="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3">
                            <p className="text-xs font-bold text-amber-800">
                                No exchange rate on file for these institutions:
                            </p>
                            <ul className="mt-1 list-disc space-y-0.5 pl-4 text-[11px] text-amber-700">
                                {rollup.unconverted.map((row, index) => (
                                    <li key={index}>
                                        {row.institution} — {row.amount} {row.currency}
                                    </li>
                                ))}
                            </ul>
                            <p className="mt-1.5 text-[11px] text-amber-700">
                                Record a rate below and their revenue will be included in the total.
                            </p>
                        </div>
                    )}

                    {rollup.rows?.length > 0 && (
                        <div className="mt-4 overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                    <tr>
                                        <th className="py-2 pr-4">Institution</th>
                                        <th className="py-2 pr-4">Billed</th>
                                        <th className="py-2 pr-4">In {reportingCurrency}</th>
                                        <th className="py-2">Status</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {rollup.rows.map((row, index) => (
                                        <tr key={index}>
                                            <td className="py-2 pr-4 text-xs font-semibold text-slate-700">{row.institution}</td>
                                            <td className="py-2 pr-4 text-xs text-slate-500">
                                                {row.amount} {row.currency}
                                            </td>
                                            <td className="py-2 pr-4 text-xs font-bold text-slate-900">
                                                {row.converted === null ? (
                                                    <span className="text-amber-600">—</span>
                                                ) : (
                                                    row.converted
                                                )}
                                            </td>
                                            <td className="py-2 text-[11px] text-slate-400">{row.status || '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>

                <div className="flex items-center justify-between">
                    <h3 className="text-sm font-bold text-slate-900">Rate snapshots</h3>
                    <button
                        type="button"
                        onClick={() => setShowForm((v) => !v)}
                        data-testid="fx-new-rate"
                        className="rounded-lg bg-[var(--accent)] px-4 py-2 text-sm font-semibold text-white shadow-sm transition-opacity hover:opacity-90"
                    >
                        {showForm ? 'Cancel' : 'Record a rate'}
                    </button>
                </div>

                {/* ---- Record a rate ---- */}
                {showForm && (
                    <form onSubmit={submit}>
                        <div className="space-y-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-xs">
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                <Field
                                    label="From"
                                    name="base_code"
                                    type="select"
                                    required
                                    value={data.base_code}
                                    error={errors.base_code}
                                    options={currencyOptions}
                                    onChange={(e) => setData('base_code', e.target.value)}
                                />
                                <Field
                                    label="To"
                                    name="quote_code"
                                    type="select"
                                    required
                                    value={data.quote_code}
                                    error={errors.quote_code}
                                    options={currencyOptions}
                                    onChange={(e) => setData('quote_code', e.target.value)}
                                />
                                <Field
                                    label="Rate"
                                    name="rate"
                                    type="number"
                                    required
                                    step="0.0000000001"
                                    value={data.rate}
                                    error={errors.rate}
                                    placeholder="e.g. 0.0091"
                                    hint={`How many ${data.quote_code} one ${data.base_code} buys.`}
                                    onChange={(e) => setData('rate', e.target.value)}
                                />
                                <Field
                                    label="Effective from"
                                    name="effective_on"
                                    type="date"
                                    required
                                    value={data.effective_on}
                                    error={errors.effective_on}
                                    onChange={(e) => setData('effective_on', e.target.value)}
                                />
                            </div>

                            <div className="flex justify-end border-t border-slate-100 pt-4">
                                <button
                                    type="submit"
                                    disabled={processing}
                                    data-testid="fx-rate-submit"
                                    className="inline-flex items-center gap-2 rounded-xl bg-[var(--accent)] px-6 py-2.5 text-sm font-bold text-white shadow-sm transition-opacity hover:opacity-90 disabled:opacity-50"
                                >
                                    {processing && <Spinner className="h-4 w-4" />}
                                    {processing ? 'Saving...' : 'Save rate snapshot'}
                                </button>
                            </div>
                        </div>
                    </form>
                )}

                {/* ---- Snapshot table ---- */}
                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                <tr>
                                    <th className="px-6 py-3">Pair</th>
                                    <th className="px-6 py-3">Rate</th>
                                    <th className="px-6 py-3">Effective</th>
                                    <th className="px-6 py-3">Source</th>
                                    <th className="px-6 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {rows.length > 0 ? rows.map((rate) => (
                                    <tr key={rate.id} data-testid="fx-rate-row">
                                        <td className="px-6 py-3 text-xs font-bold text-slate-800">
                                            {rate.base_code} → {rate.quote_code}
                                        </td>
                                        <td className="px-6 py-3 font-mono text-xs text-slate-600">{rate.rate}</td>
                                        <td className="px-6 py-3 text-xs text-slate-500">{rate.effective_on}</td>
                                        <td className="px-6 py-3 text-[11px] text-slate-400">{rate.source}</td>
                                        <td className="px-6 py-3 text-right">
                                            <button
                                                type="button"
                                                onClick={() => remove(rate)}
                                                data-testid="fx-rate-delete"
                                                className="text-xs font-semibold text-rose-500 transition-colors hover:text-rose-700"
                                            >
                                                Remove
                                            </button>
                                        </td>
                                    </tr>
                                )) : (
                                    <tr>
                                        <td colSpan={5} className="px-6 py-12 text-center text-xs text-slate-400">
                                            No rate snapshots recorded yet.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                <InfoHint tone="indigo">
                    Conversions try the direct pair first, then the inverse - so recording only
                    <strong> BDT → {reportingCurrency}</strong> is enough to convert
                    {reportingCurrency} → BDT as well.
                </InfoHint>
            </div>
        </SettingsLayout>
    );
}