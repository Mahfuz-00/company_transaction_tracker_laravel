import React from 'react';

/**
 * The three-month predictive forecast panel.
 *
 * Extracted from Analytics so the page file stays lean. Explains its own maths,
 * because a number a manager cannot reason about is a number they will not act
 * on. Receives an already-bound `money()` formatter from the parent so it obeys
 * the global number/abbreviation settings.
 */
export default function ForecastPanel({ forecast, money }) {
    if (!forecast?.forecast) return null;

    const f = forecast.forecast;
    const a = forecast.assumptions || {};
    const horizon = forecast.horizon || [];

    return (
        <div className="overflow-hidden rounded-2xl border-slate-200/80 bg-white shadow-sm">
            <div className="flex flex-col gap-2 border-b border-slate-100 p-5 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 className="text-base font-bold text-slate-900">3-Month Predictive Forecast</h3>
                    <p className="mt-0.5 text-xs text-slate-500">{a.method}</p>
                </div>
                <span className="inline-flex flex-shrink-0 items-center rounded-full bg-[var(--accent-soft)] px-3 py-1 text-[11px] font-bold text-[var(--accent)]">
                    Based on {a.lookback_months} months
                </span>
            </div>

            {/* Headline projection for next month */}
            <div className="grid grid-cols-2 gap-px bg-slate-100 lg:grid-cols-4">
                {[
                    { label: 'Projected Meals', value: f.projected_meals, hint: `${f.meal_growth_pct}% trend` },
                    { label: 'Projected Cost', value: money(f.projected_cost, false), hint: `${f.expense_growth_pct}% cost trend` },
                    { label: 'Subsidy Required', value: money(f.subsidy_required, false), hint: `${a.target_subsidy_ratio}% target` },
                    { label: 'Members Fund', value: money(f.member_funded, false), hint: `${a.target_member_ratio}% target` },
                ].map((cell) => (
                    <div key={cell.label} className="bg-white px-5 py-4">
                        <div className="text-[10px] font-semibold uppercase tracking-wider text-slate-400">{cell.label}</div>
                        <div className="mt-0.5 text-lg font-bold text-slate-800">{cell.value}</div>
                        <div className="text-[11px] text-slate-400">{cell.hint}</div>
                    </div>
                ))}
            </div>

            {/* Forward horizon table */}
            <div className="overflow-x-auto">
                <table className="w-full min-w-140 border-collapse text-left">
                    <thead>
                        <tr className="border-y border-slate-100 bg-slate-50 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                            <th className="px-5 py-3">Month</th>
                            <th className="px-5 py-3 text-right">Meals</th>
                            <th className="px-5 py-3 text-right">Cost</th>
                            <th className="px-5 py-3 text-right">Per-Meal Rate</th>
                            <th className="px-5 py-3 text-right">Subsidy Needed</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 text-sm">
                        {horizon.map((row) => (
                            <tr key={row.month} className="transition-colors hover:bg-slate-50/60">
                                <td className="px-5 py-3 font-semibold text-slate-700">{row.label}</td>
                                <td className="px-5 py-3 text-right text-slate-600">{row.projected_meals}</td>
                                <td className="px-5 py-3 text-right text-slate-600">{money(row.projected_cost, false)}</td>
                                <td className="px-5 py-3 text-right text-slate-600">{money(row.projected_rate, false)}</td>
                                <td className="px-5 py-3 text-right font-bold text-sky-600">{money(row.subsidy_required, false)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="border-t border-slate-100 bg-slate-50/60 px-5 py-3">
                <p className="text-[11px] leading-relaxed text-slate-500">
                    The forecast holds the institution's <strong className="font-semibold text-slate-700">
                        {a.target_member_ratio}/{a.target_subsidy_ratio} rule</strong>: members are expected to
                    cover {a.target_member_ratio}% of the meal cost and subsidies the remaining {a.target_subsidy_ratio}%.
                    The "Subsidy Needed" column is the exact funding required next month to keep that split.
                </p>
            </div>
        </div>
    );
}
