import React from 'react';

/**
 * Subsidy tracking table: each funder's target share vs. what was actually
 * recorded this month. Extracted from Analytics.
 */
export default function SubsidyTrackingPanel({ tracking, money }) {
    const sources = tracking?.sources || [];

    if (sources.length === 0) return null;

    return (
        <div className="overflow-hidden rounded-2xl border-slate-200/80 bg-white shadow-sm">
            <div className="flex flex-col gap-1 border-b border-slate-100 p-5 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 className="text-base font-bold text-slate-900">Subsidy Tracking</h3>
                    <p className="mt-0.5 text-xs text-slate-500">
                        Funding recorded this month, by source, against each source's target share.
                    </p>
                </div>
                <span className="inline-flex flex-shrink-0 items-center rounded-full bg-sky-50 px-3 py-1 text-[11px] font-bold text-sky-700">
                    {money(tracking.total, false)} total
                </span>
            </div>

            <div className="overflow-x-auto">
                <table className="w-full border-collapse text-left">
                    <thead>
                        <tr className="border-b border-slate-100 bg-slate-50 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                            <th className="px-5 py-3">Source</th>
                            <th className="px-5 py-3 text-right">Recorded</th>
                            <th className="px-5 py-3 text-right">Target Share</th>
                            <th className="px-5 py-3 text-right">Actual Share</th>
                            <th className="px-5 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 text-sm">
                        {sources.map((src) => {
                            const target = Number(src.target_percentage || 0);
                            const actual = Number(src.actual_percentage || 0);
                            const onTrack = target === 0 || Math.abs(actual - target) <= 5;

                            return (
                                <tr key={src.key} className="transition-colors hover:bg-slate-50/60">
                                    <td className="px-5 py-3 font-semibold text-slate-700">{src.name}</td>
                                    <td className="px-5 py-3 text-right text-slate-600">{money(src.total, false)}</td>
                                    <td className="px-5 py-3 text-right text-slate-600">{target}%</td>
                                    <td className="px-5 py-3 text-right font-bold text-slate-800">{actual}%</td>
                                    <td className="px-5 py-3">
                                        <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-bold ${onTrack ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}`}>
                                            {onTrack ? 'On target' : 'Off target'}
                                        </span>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
