import React from 'react';

/**
 * A summary metric card.
 *
 * Extracted from the (once-monolithic) Analytics page so it can be reused by any
 * dashboard. The trend chip sits AFTER the title, the value stands alone, and the
 * icon is a fixed square pinned top-right - so a large figure can never squash
 * the icon or shift the indicator.
 */
const TONES = {
    slate: { text: 'text-slate-800', tile: 'bg-slate-50 text-slate-500 border-slate-100' },
    emerald: { text: 'text-emerald-600', tile: 'bg-emerald-50 text-emerald-600 border-emerald-100/60' },
    rose: { text: 'text-rose-600', tile: 'bg-rose-50 text-rose-600 border-rose-100/60' },
    sky: { text: 'text-sky-600', tile: 'bg-sky-50 text-sky-600 border-sky-100/60' },
    amber: { text: 'text-amber-600', tile: 'bg-amber-50 text-amber-600 border-amber-100/60' },
    accent: { text: 'text-[var(--accent)]', tile: 'bg-[var(--accent-soft)] text-[var(--accent)] border-transparent' },
};

/** Percentage change between two values, rounded, or null when there is no base. */
export function pctChange(previous, current) {
    const prev = Number(previous || 0);
    const curr = Number(current || 0);

    if (prev === 0) return null;

    return Math.round(((curr - prev) / Math.abs(prev)) * 100);
}

export default function MetricCard({ label, value, tone = 'slate', icon, trend, hint }) {
    const palette = TONES[tone] || TONES.slate;
    const hasTrend = trend !== undefined && trend !== null;
    const trendUp = hasTrend && Number(trend) >= 0;

    return (
        <div className="flex items-start justify-between gap-4 rounded-2xl border-slate-200/80 bg-white p-5 shadow-sm transition-shadow hover:shadow-md">
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <p className="text-xs font-bold uppercase tracking-wider text-slate-400">{label}</p>

                    {hasTrend && (
                        <span
                            className={`inline-flex flex-shrink-0 items-center gap-0.5 whitespace-nowrap rounded-full px-1.5 py-0.5 text-[10px] font-bold leading-none ${trendUp ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'}`}
                            title={`${trendUp ? 'Increase' : 'Decrease'} vs previous period`}
                        >
                            <svg className="h-2.5 w-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="3" d={trendUp ? 'M5 15l7-7 7 7' : 'M19 9l-7 7-7-7'} />
                            </svg>
                            {trendUp ? '+' : ''}{trend}%
                        </span>
                    )}
                </div>

                <h3 className={`mt-1.5 truncate text-2xl font-extrabold leading-tight ${palette.text}`} title={String(value)}>
                    {value}
                </h3>

                {hint && <p className="mt-1 text-[11px] text-slate-400">{hint}</p>}
            </div>

            {icon && (
                <div className={`flex h-11 w-11 flex-shrink-0 items-center justify-center self-start rounded-xl border ${palette.tile}`}>
                    {icon}
                </div>
            )}
        </div>
    );
}
