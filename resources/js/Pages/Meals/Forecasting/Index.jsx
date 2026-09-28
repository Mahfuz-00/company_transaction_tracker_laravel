import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageHint, InfoHint } from '@/Components/Help/HelpHint';
import Field from '@/Components/UI/Field';
import { Spinner } from '@/Components/UI/Loading';
import { Head, router, useForm, usePage } from '@inertiajs/react';

/**
 * AI FORECASTING (RAG / vector-based).
 *
 * Predicts tomorrow's meal count, cost per meal and expected expense, and - the
 * part that makes it trustworthy - shows WHICH PAST DAYS the estimate was drawn
 * from. That evidence trail is the point of doing retrieval-augmented generation
 * here rather than fitting an opaque model.
 *
 * The page always states its BASIS:
 *   - `history`   : data-driven, with the retrieved days listed.
 *   - `benchmark` : a country-level fallback, clearly labelled as such, used when
 *                   the institution has under the minimum months of history.
 */
export default function Index({ forecast = {}, tomorrow = {}, basis = {}, days = 7, benchmarks = [], metricOptions = [] }) {
    const { flash } = usePage().props;

    const [showBenchmarkForm, setShowBenchmarkForm] = useState(false);

    const benchmarkForm = useForm({
        country_code: basis.country || 'BD',
        metric: metricOptions[0]?.value || 'cost_per_meal',
        period_month: new Date().toISOString().slice(0, 7) + '-01',
        value: '',
        unit: '',
        source: '',
        notes: '',
    });

    const embed = () => {
        router.post(route('meals.forecasting.embed'), {}, { preserveScroll: true });
    };

    const submitBenchmark = (e) => {
        e.preventDefault();
        benchmarkForm.post(route('meals.forecasting.benchmarks.store'), {
            preserveScroll: true,
            onSuccess: () => { benchmarkForm.reset('value', 'notes'); setShowBenchmarkForm(false); },
        });
    };

    const isBenchmark = tomorrow.basis === 'benchmark';
    const confidencePct = Math.round((tomorrow.confidence ?? 0) * 100);

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p className="text-xs font-medium text-slate-500">
                            Predicted meals, cost per meal and expected expense — with the evidence behind it.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={embed}
                        data-testid="forecast-embed-button"
                        className="inline-flex items-center gap-2 self-start rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50"
                    >
                        Rebuild from history
                    </button>
                </div>
            }
        >
            <Head title="AI Forecasting" />

            <div className="space-y-6">
                <PageHint title="How this forecast is produced">
                    Each past day is reduced to a numeric vector and stored. To predict a day, we find the most{' '}
                    <strong className="font-semibold text-slate-700">similar historical days</strong> and use their
                    actual outcomes as the estimate. The evidence is listed below, so you can judge the number rather
                    than trust it blindly.
                </PageHint>

                {flash?.success && (
                    <div role="status" data-testid="forecast-flash" className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
                        {flash.error}
                    </div>
                )}

                {/* ---- The basis banner: history vs benchmark ---- */}
                {isBenchmark ? (
                    <div data-testid="forecast-basis-benchmark" className="rounded-xl border border-amber-200 bg-amber-50 p-4">
                        <p className="text-sm font-bold text-amber-800">
                            Benchmark estimate for {basis.country}
                        </p>
                        <p className="mt-0.5 text-xs leading-relaxed text-amber-700">
                            This institution has about <strong>{basis.history_months}</strong> month(s) of history,
                            which is below the {basis.min_history_months}-month threshold for a data-driven forecast.
                            The figures below come from national aggregates and should be read as an industry
                            baseline, not a prediction about your members.
                        </p>
                    </div>
                ) : (
                    <div data-testid="forecast-basis-history" className="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                        <p className="text-sm font-bold text-emerald-800">
                            Data-driven forecast
                        </p>
                        <p className="mt-0.5 text-xs text-emerald-700">
                            Based on {basis.embedded_days} embedded day(s) of your own history, retrieved by
                            similarity to tomorrow.
                        </p>
                    </div>
                )}

                {/* ---- Tomorrow's headline forecast ---- */}
                <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <Metric label="Expected meals" value={tomorrow.meals ?? 0} testid="forecast-meals" tone="text-indigo-600" />
                    <Metric label="Expected eaters" value={tomorrow.headcount ?? 0} testid="forecast-headcount" />
                    <Metric label="Cost per meal" value={tomorrow.cost_per_meal ?? 0} testid="forecast-cost" />
                    <Metric label="Expected expense" value={tomorrow.expense ?? 0} testid="forecast-expense" tone="text-rose-600" />
                </div>

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    {/* ---- Confidence + notes ---- */}
                    <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-xs">
                        <h3 className="text-sm font-bold text-slate-900">Confidence</h3>

                        <div className="mt-3">
                            <div className="flex items-end justify-between">
                                <span data-testid="forecast-confidence" className="text-3xl font-extrabold text-slate-900">
                                    {confidencePct}%
                                </span>
                                <span className="text-[11px] text-slate-400">of a maximum 95%</span>
                            </div>
                            <div className="mt-2 h-2 w-full overflow-hidden rounded-full bg-slate-100">
                                <div
                                    className={`h-full rounded-full ${confidencePct >= 60 ? 'bg-emerald-500' : confidencePct >= 35 ? 'bg-amber-500' : 'bg-rose-500'}`}
                                    style={{ width: `${confidencePct}%` }}
                                />
                            </div>
                        </div>

                        {tomorrow.notes?.length > 0 && (
                            <ul className="mt-4 space-y-1.5 border-t border-slate-100 pt-3 text-[11px] leading-relaxed text-slate-500">
                                {tomorrow.notes.map((note, index) => (
                                    <li key={index}>· {note}</li>
                                ))}
                            </ul>
                        )}

                        {tomorrow.subsidy_share > 0 && (
                            <div className="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-3 text-[11px] text-slate-600">
                                <p className="font-semibold text-slate-700">Who funds it</p>
                                <p className="mt-1">
                                    Subsidy: <strong>{tomorrow.subsidy_share}</strong> ·
                                    Members: <strong>{tomorrow.member_funded}</strong>
                                </p>
                            </div>
                        )}
                    </div>

                    {/* ---- Evidence (the RAG grounding) ---- */}
                    <div className="lg:col-span-2 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs">
                        <div className="border-b border-slate-100 bg-slate-50/70 px-6 py-3">
                            <h3 className="text-sm font-bold text-slate-900">Evidence used</h3>
                            <p className="mt-0.5 text-[11px] text-slate-500">
                                The most similar past days, ranked by similarity.
                            </p>
                        </div>

                        <div className="max-h-80 overflow-y-auto">
                            {tomorrow.evidence?.length > 0 ? (
                                <table className="w-full text-left text-sm">
                                    <thead className="sticky top-0 bg-white text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                        <tr>
                                            <th className="px-6 py-2">Day</th>
                                            <th className="px-6 py-2">Similarity</th>
                                            <th className="px-6 py-2">Meals</th>
                                            <th className="px-6 py-2">Cost/meal</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {tomorrow.evidence.map((row, index) => (
                                            <tr key={index} data-testid="forecast-evidence-row">
                                                <td className="px-6 py-2.5 text-xs text-slate-700">{row.date}</td>
                                                <td className="px-6 py-2.5 text-xs text-slate-500">
                                                    {(row.similarity * 100).toFixed(0)}%
                                                </td>
                                                <td className="px-6 py-2.5 text-xs font-semibold text-slate-800">{row.meals}</td>
                                                <td className="px-6 py-2.5 text-xs text-slate-600">{row.cost_per_meal}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            ) : (
                                <p className="px-6 py-10 text-center text-xs text-slate-400">
                                    No historical days were similar enough to use as evidence - the figures come
                                    from the benchmark instead.
                                </p>
                            )}
                        </div>
                    </div>
                </div>

                {/* ---- Multi-day outlook ---- */}
                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs">
                    <div className="flex flex-col gap-2 border-b border-slate-100 bg-slate-50/70 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <h3 className="text-sm font-bold text-slate-900">Next {days} days</h3>
                        <div className="flex gap-4 text-xs">
                            <span className="text-slate-500">
                                Total meals: <strong className="font-bold text-slate-800">{forecast.total_meals ?? 0}</strong>
                            </span>
                            <span className="text-slate-500">
                                Total expense: <strong className="font-bold text-slate-800">{forecast.total_expense ?? 0}</strong>
                            </span>
                        </div>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                <tr>
                                    <th className="px-6 py-3">Date</th>
                                    <th className="px-6 py-3">Meals</th>
                                    <th className="px-6 py-3">Eaters</th>
                                    <th className="px-6 py-3">Cost/meal</th>
                                    <th className="px-6 py-3">Expense</th>
                                    <th className="px-6 py-3">Basis</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {forecast.days?.map((day, index) => (
                                    <tr key={index} data-testid="forecast-day-row">
                                        <td className="px-6 py-2.5 text-xs text-slate-700">{day.date}</td>
                                        <td className="px-6 py-2.5 text-xs font-semibold text-slate-800">{day.meals}</td>
                                        <td className="px-6 py-2.5 text-xs text-slate-600">{day.headcount}</td>
                                        <td className="px-6 py-2.5 text-xs text-slate-600">{day.cost_per_meal}</td>
                                        <td className="px-6 py-2.5 text-xs font-semibold text-slate-900">{day.expense}</td>
                                        <td className="px-6 py-2.5">
                                            <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-bold uppercase ${day.basis === 'history'
                                                ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                                : 'border-amber-200 bg-amber-50 text-amber-700'}`}>
                                                {day.basis}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* ---- Benchmark corpus (the fallback data) ---- */}
                <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-xs">
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 className="text-sm font-bold text-slate-900">
                                {basis.country} benchmarks
                            </h3>
                            <p className="mt-0.5 text-xs text-slate-500">
                                Country aggregates used when an institution has too little history of its own.
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={() => setShowBenchmarkForm((v) => !v)}
                            data-testid="benchmark-new"
                            className="self-start rounded-lg border border-slate-300 bg-white px-3.5 py-1.5 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-50"
                        >
                            {showBenchmarkForm ? 'Cancel' : 'Add a benchmark'}
                        </button>
                    </div>

                    {showBenchmarkForm && (
                        <form onSubmit={submitBenchmark} className="mt-4 space-y-4 rounded-xl border border-slate-200 bg-slate-50/60 p-4">
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                <Field
                                    label="Country"
                                    name="country_code"
                                    required
                                    value={benchmarkForm.data.country_code}
                                    error={benchmarkForm.errors.country_code}
                                    placeholder="BD"
                                    onChange={(e) => benchmarkForm.setData('country_code', e.target.value.toUpperCase())}
                                />
                                <Field
                                    label="Metric"
                                    name="metric"
                                    type="select"
                                    required
                                    value={benchmarkForm.data.metric}
                                    error={benchmarkForm.errors.metric}
                                    options={metricOptions}
                                    onChange={(e) => benchmarkForm.setData('metric', e.target.value)}
                                />
                                <Field
                                    label="Month"
                                    name="period_month"
                                    type="date"
                                    required
                                    value={benchmarkForm.data.period_month}
                                    error={benchmarkForm.errors.period_month}
                                    onChange={(e) => benchmarkForm.setData('period_month', e.target.value)}
                                />
                                <Field
                                    label="Value"
                                    name="value"
                                    type="number"
                                    step="0.0001"
                                    required
                                    value={benchmarkForm.data.value}
                                    error={benchmarkForm.errors.value}
                                    placeholder="e.g. 45.50"
                                    onChange={(e) => benchmarkForm.setData('value', e.target.value)}
                                />
                            </div>

                            <Field
                                label="Source"
                                name="source"
                                value={benchmarkForm.data.source}
                                error={benchmarkForm.errors.source}
                                placeholder="e.g. Bangladesh Bureau of Statistics, 2026"
                                onChange={(e) => benchmarkForm.setData('source', e.target.value)}
                            />

                            <div className="flex justify-end">
                                <button
                                    type="submit"
                                    disabled={benchmarkForm.processing}
                                    data-testid="benchmark-submit"
                                    className="inline-flex items-center gap-2 rounded-lg bg-[var(--accent)] px-5 py-2 text-sm font-semibold text-white shadow-sm transition-opacity hover:opacity-90 disabled:opacity-50"
                                >
                                    {benchmarkForm.processing && <Spinner className="h-4 w-4" />}
                                    {benchmarkForm.processing ? 'Saving...' : 'Save benchmark'}
                                </button>
                            </div>
                        </form>
                    )}

                    {benchmarks.length > 0 && (
                        <div className="mt-4 overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                    <tr>
                                        <th className="py-2 pr-4">Metric</th>
                                        <th className="py-2 pr-4">Month</th>
                                        <th className="py-2 pr-4">Value</th>
                                        <th className="py-2">Source</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {benchmarks.map((row) => (
                                        <tr key={row.id}>
                                            <td className="py-2 pr-4 text-xs font-semibold text-slate-700">{row.metric_label}</td>
                                            <td className="py-2 pr-4 text-xs text-slate-500">{row.period_month}</td>
                                            <td className="py-2 pr-4 text-xs font-bold text-slate-900">{row.value}</td>
                                            <td className="py-2 text-[11px] text-slate-400">{row.source || '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>

                <InfoHint tone="indigo">
                    A forecast is an estimate, never a certainty. Use it to plan quantities and spot drift - and
                    override it whenever you know something the history does not.
                </InfoHint>
            </div>
        </AuthenticatedLayout>
    );
}

/** A headline numeric card. */
function Metric({ label, value, tone = 'text-slate-900', testid = null }) {
    return (
        <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
            <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">{label}</p>
            <p data-testid={testid} className={`mt-1.5 text-2xl font-extrabold ${tone}`}>{value}</p>
        </div>
    );
}