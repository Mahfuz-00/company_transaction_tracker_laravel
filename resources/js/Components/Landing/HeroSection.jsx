import React from 'react';
import { Link } from '@inertiajs/react';
import { ArrowRight } from './LandingPrimitives';

/**
 * Landing hero: the value proposition, primary calls to action, and an animated
 * app preview. The `wa-rise` / `wa-pulse` / `wa-float` classes come from
 * LandingAnimationStyles, declared once at the page root.
 */
export default function HeroSection({ institutionCount = 0 }) {
    const stats = [
        { label: 'Institutions', value: institutionCount > 0 ? `${institutionCount}+` : 'Multi-tenant' },
        { label: 'Meals tracked', value: 'B / L / D' },
        { label: 'Data isolation', value: 'Strict' },
    ];

    const preview = [
        { label: 'Collected', value: '42,500', tone: 'text-emerald-600', bg: 'bg-emerald-50', border: 'border-emerald-100' },
        { label: 'Spent', value: '31,240', tone: 'text-rose-600', bg: 'bg-rose-50', border: 'border-rose-100' },
        { label: 'Balance', value: '11,260', tone: 'text-indigo-600', bg: 'bg-indigo-50', border: 'border-indigo-100' },
    ];

    const members = [
        { name: 'Farhan Hossain', meals: 62, due: '1,240', status: 'Paid', tone: 'bg-emerald-50 text-emerald-700 border-emerald-100' },
        { name: 'Rakib Ahmed', meals: 58, due: '460', status: 'Partial', tone: 'bg-amber-50 text-amber-700 border-amber-100' },
        { name: 'Sadia Afrin', meals: 44, due: '880', status: 'Due', tone: 'bg-rose-50 text-rose-700 border-rose-100' },
    ];

    return (
        <section className="relative overflow-hidden">
            <div aria-hidden="true" className="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(ellipse_80%_60%_at_50%_-20%,rgba(99,102,241,0.12),transparent)]" />
            <div aria-hidden="true" className="wa-pulse pointer-events-none absolute -top-32 right-0 -z-10 h-96 w-96 rounded-full bg-indigo-300/30 blur-3xl" />
            <div aria-hidden="true" className="wa-pulse pointer-events-none absolute top-40 -left-20 -z-10 h-80 w-80 rounded-full bg-sky-300/25 blur-3xl" style={{ animationDelay: '1.5s' }} />

            <div className="mx-auto max-w-7xl px-4 pb-20 pt-16 sm:px-6 sm:pt-24 lg:px-8 lg:pb-28">
                <div className="grid items-center gap-14 lg:grid-cols-2 lg:gap-12">
                    <div className="max-w-xl lg:max-w-none">
                        <span className="wa-rise inline-flex items-center gap-2 rounded-full border-indigo-100 bg-indigo-50/80 px-3.5 py-1.5 text-xs font-semibold text-indigo-700 shadow-sm shadow-indigo-500/5">
                            <span className="relative flex h-2 w-2">
                                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-indigo-400 opacity-60" />
                                <span className="relative inline-flex h-2 w-2 rounded-full bg-indigo-500" />
                            </span>
                            Built for multi-tenant isolation
                        </span>

                        <h1 className="wa-rise wa-delay-1 mt-6 text-4xl font-extrabold leading-[1.1] tracking-tight text-slate-900 sm:text-5xl lg:text-[3.4rem]">
                            One platform for every
                            <span className="mt-1 block bg-gradient-to-r from-indigo-600 via-indigo-500 to-sky-500 bg-clip-text text-transparent">
                                shared meal &amp; expense ledger
                            </span>
                        </h1>

                        <p className="wa-rise wa-delay-2 mt-6 text-base leading-relaxed text-slate-600 sm:text-lg">
                            Run meal programs for offices, university halls, colleges and general messes on a
                            single, fully-isolated platform. Track the money members pool, the meals they eat,
                            and every expense — turning a handwritten register into a live, always-balanced ledger.
                        </p>

                        <div className="wa-rise wa-delay-3 mt-9 flex flex-col gap-3 sm:flex-row sm:items-center">
                            <Link
                                href={route('register')}
                                className="inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-600 px-7 py-3.5 text-sm font-bold text-white shadow-lg shadow-indigo-600/25 transition-all hover:bg-indigo-700 hover:shadow-xl hover:shadow-indigo-600/30 active:scale-[.98]"
                            >
                                Create Free Account
                                <ArrowRight />
                            </Link>
                            <a
                                href="#contact"
                                className="inline-flex items-center justify-center gap-2 rounded-xl border-slate-200 bg-white px-7 py-3.5 text-sm font-bold text-slate-700 shadow-sm transition-all hover:border-slate-300 hover:bg-slate-50 hover:text-slate-900"
                            >
                                Request a demo
                            </a>
                        </div>

                        <dl className="wa-rise wa-delay-4 mt-12 grid grid-cols-3 gap-4 border-t border-slate-200/80 pt-7 sm:gap-8">
                            {stats.map((item) => (
                                <div key={item.label}>
                                    <dt className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">{item.label}</dt>
                                    <dd className="mt-1.5 text-sm font-bold text-slate-800 sm:text-base">{item.value}</dd>
                                </div>
                            ))}
                        </dl>
                    </div>

                    {/* App preview mock */}
                    <div className="wa-rise wa-delay-2 relative">
                        <div aria-hidden="true" className="absolute -inset-6 -z-10 rounded-[2rem] bg-gradient-to-tr from-indigo-100/80 via-white to-sky-100/80 opacity-80 blur-2xl" />
                        <div className="wa-float overflow-hidden rounded-2xl border-slate-200/80 bg-white shadow-2xl shadow-slate-900/10 ring-1 ring-slate-900/5">
                            <div className="flex items-center gap-2 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white px-4 py-3">
                                <span className="h-2.5 w-2.5 rounded-full bg-rose-400 shadow-sm shadow-rose-400/40" />
                                <span className="h-2.5 w-2.5 rounded-full bg-amber-400 shadow-sm shadow-amber-400/40" />
                                <span className="h-2.5 w-2.5 rounded-full bg-emerald-400 shadow-sm shadow-emerald-400/40" />
                                <span className="ml-3 text-[11px] font-semibold text-slate-400">Meal Report — This month</span>
                            </div>
                            <div className="space-y-5 p-5">
                                <div className="grid grid-cols-3 gap-3">
                                    {preview.map((c) => (
                                        <div key={c.label} className={`rounded-xl border ${c.border} ${c.bg} p-3.5`}>
                                            <div className="text-[10px] font-semibold uppercase tracking-wider text-slate-500">{c.label}</div>
                                            <div className={`mt-1.5 text-sm font-bold tabular-nums ${c.tone}`}>{c.value}</div>
                                        </div>
                                    ))}
                                </div>
                                <div className="space-y-2">
                                    {members.map((row) => (
                                        <div key={row.name} className="flex items-center gap-3 rounded-xl border-slate-100 bg-slate-50/40 px-3.5 py-2.5 transition-colors hover:bg-white">
                                            <div className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-indigo-500 to-indigo-600 text-[10px] font-bold text-white shadow-sm shadow-indigo-500/30">
                                                {row.name.split(' ').map((p) => p[0]).slice(0, 2).join('')}
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <div className="truncate text-xs font-semibold text-slate-800">{row.name}</div>
                                                <div className="text-[10px] font-medium text-slate-400">{row.meals} meals this month</div>
                                            </div>
                                            <div className="text-right">
                                                <div className="text-xs font-bold tabular-nums text-slate-800">{row.due}</div>
                                                <span className={`mt-0.5 inline-block rounded-full border px-2 py-0.5 text-[9px] font-semibold ${row.tone}`}>{row.status}</span>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    );
}
