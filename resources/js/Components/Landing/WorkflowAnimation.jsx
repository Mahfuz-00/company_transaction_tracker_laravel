import React, { useEffect, useRef, useState } from 'react';
import { SectionHeading } from './LandingPrimitives';
import { WORKFLOW } from './landingContent';

/**
 * The interactive, auto-advancing workflow section.
 *
 * Auto-advances through the four onboarding steps every 4.2s, pauses on hover /
 * when a step is clicked, and renders a matching animated scene. Self-contained:
 * it owns its own timer + active state so the page shell stays declarative.
 */
export default function WorkflowAnimation() {
    const [active, setActive] = useState(0);
    const [paused, setPaused] = useState(false);
    const timer = useRef(null);

    useEffect(() => {
        if (paused) return undefined;
        timer.current = setTimeout(() => setActive((a) => (a + 1) % WORKFLOW.length), 4200);
        return () => clearTimeout(timer.current);
    }, [active, paused]);

    const current = WORKFLOW[active];

    return (
        <section
            id="workflow"
            className="scroll-mt-24 border-t border-slate-100 bg-slate-50/60 py-24"
            onMouseEnter={() => setPaused(true)}
            onMouseLeave={() => setPaused(false)}
        >
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <SectionHeading
                    eyebrow="See it in action"
                    title="Up and running in four smooth steps"
                    body="Watch how an institution onboards, invites its members, and tracks daily meals — no accounting background needed."
                />

                <div className="mt-16 grid items-center gap-12 lg:grid-cols-2">
                    {/* Step list */}
                    <ol className="space-y-3">
                        {WORKFLOW.map((item, i) => {
                            const isActive = i === active;
                            return (
                                <li key={item.step}>
                                    <button
                                        type="button"
                                        onClick={() => setActive(i)}
                                        className={`w-full rounded-2xl border p-5 text-left transition-all duration-300 ${isActive
                                                ? 'border-indigo-200 bg-white shadow-lg shadow-indigo-500/10 ring-1 ring-indigo-100'
                                                : 'border-slate-200/80 bg-white/70 hover:border-slate-300 hover:bg-white hover:shadow-sm'
                                            }`}
                                    >
                                        <div className="flex items-start gap-4">
                                            <span
                                                className={`flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl text-xs font-bold transition-all ${isActive ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30' : 'bg-slate-100 text-slate-600'
                                                    }`}
                                            >
                                                {item.step}
                                            </span>
                                            <div className="min-w-0 pt-0.5">
                                                <h3 className="text-base font-bold text-slate-900">{item.title}</h3>
                                                <p className={`mt-1.5 text-sm leading-relaxed text-slate-600 transition-opacity ${isActive ? 'opacity-100' : 'opacity-60'}`}>
                                                    {item.body}
                                                </p>
                                            </div>
                                        </div>
                                        <div className="mt-4 h-1 overflow-hidden rounded-full bg-slate-100">
                                            <div
                                                className={`h-full rounded-full bg-gradient-to-r from-indigo-500 to-sky-400 transition-all ${isActive && !paused ? 'w-full' : 'w-0'}`}
                                                style={{ transitionDuration: isActive && !paused ? '4200ms' : '200ms' }}
                                            />
                                        </div>
                                    </button>
                                </li>
                            );
                        })}
                    </ol>

                    {/* Animated scene */}
                    <div className="relative">
                        <div aria-hidden="true" className="absolute -inset-5 -z-10 rounded-3xl bg-gradient-to-br from-indigo-100/70 via-white to-sky-100/70 opacity-80 blur-2xl" />
                        <div className="overflow-hidden rounded-2xl border-slate-200/80 bg-white shadow-2xl shadow-slate-900/10 ring-1 ring-slate-900/5">
                            <div className="flex items-center gap-2 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white px-4 py-3">
                                <span className="h-2.5 w-2.5 rounded-full bg-rose-400" />
                                <span className="h-2.5 w-2.5 rounded-full bg-amber-400" />
                                <span className="h-2.5 w-2.5 rounded-full bg-emerald-400" />
                                <span className="ml-3 text-[11px] font-semibold text-slate-400">
                                    Step {current.step} — {current.title}
                                </span>
                            </div>
                            <div className="min-h-[340px] p-6">
                                <Scene scene={current.scene} key={current.scene} />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    );
}

/** A small animated illustration per workflow step. */
function Scene({ scene }) {
    const base = 'wa-fade';

    if (scene === 'institution') {
        return (
            <div className={base}>
                <div className="rounded-xl border-dashed border-indigo-200/80 bg-indigo-50/50 p-6">
                    <div className="text-xs font-bold uppercase tracking-wider text-indigo-500">New workspace</div>
                    <div className="mt-4 space-y-2.5">
                        <div className="h-3.5 w-2/3 rounded-full bg-indigo-200/70" />
                        <div className="h-3.5 w-1/2 rounded-full bg-indigo-200/50" />
                    </div>
                    <div className="mt-5 flex flex-wrap gap-2">
                        {['Office', 'University', 'Mess'].map((t) => (
                            <span key={t} className="rounded-lg border-indigo-200 bg-white px-3 py-1.5 text-[11px] font-semibold text-indigo-700 shadow-sm">
                                {t}
                            </span>
                        ))}
                    </div>
                </div>
                <p className="mt-5 text-center text-xs text-slate-500">Isolated roster, ledger &amp; branding created instantly.</p>
            </div>
        );
    }

    if (scene === 'invite') {
        return (
            <div className={base}>
                <div className="rounded-xl border-slate-200 bg-white p-5 shadow-sm">
                    <div className="text-xs font-semibold text-slate-500">Invite by email</div>
                    <div className="mt-3 flex items-center gap-2 rounded-xl border-slate-200 bg-slate-50 px-3.5 py-2.5">
                        <span className="text-sm text-slate-600">member@example.com</span>
                        <span className="ml-auto rounded-lg bg-indigo-600 px-2.5 py-1 text-[10px] font-bold text-white shadow-sm">Send link</span>
                    </div>
                </div>
                <div className="mt-4 space-y-2.5">
                    {['Secure signed link', 'Member sets own password', 'Account maps to workspace'].map((t, i) => (
                        <div key={t} className={`flex items-center gap-2.5 rounded-xl border-emerald-100 bg-emerald-50/70 px-3.5 py-2.5 wa-rise wa-delay-${i + 1}`}>
                            <svg className="h-4 w-4 flex-shrink-0 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M5 13l4 4L19 7" />
                            </svg>
                            <span className="text-xs font-medium text-emerald-800">{t}</span>
                        </div>
                    ))}
                </div>
            </div>
        );
    }

    if (scene === 'meals') {
        return (
            <div className={base}>
                <div className="overflow-hidden rounded-xl border-slate-200">
                    <div className="grid grid-cols-4 bg-slate-50 px-3.5 py-2.5 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                        <span>Member</span>
                        <span className="text-center">B</span>
                        <span className="text-center">L</span>
                        <span className="text-center">D</span>
                    </div>
                    {[
                        ['Farhan', 1, 1, 1],
                        ['Rakib', 1, 0, 1],
                        ['Sadia', 0, 1, 1],
                    ].map(([name, b, l, d], i) => (
                        <div key={name} className={`grid grid-cols-4 items-center border-t border-slate-100 px-3.5 py-3 wa-rise wa-delay-${i + 1}`}>
                            <span className="text-xs font-semibold text-slate-700">{name}</span>
                            {[b, l, d].map((v, j) => (
                                <span key={j} className="text-center">
                                    <span className={`inline-flex h-7 w-7 items-center justify-center rounded-lg text-[11px] font-bold ${v ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-100 text-slate-300'}`}>
                                        {v ? '✓' : '—'}
                                    </span>
                                </span>
                            ))}
                        </div>
                    ))}
                </div>
                <p className="mt-5 text-center text-xs text-slate-500">One pass records the whole day — counts become exact costs.</p>
            </div>
        );
    }

    // ledger
    return (
        <div className={base}>
            <div className="grid grid-cols-2 gap-3.5">
                <div className="rounded-xl border-emerald-100 bg-emerald-50 p-5">
                    <div className="text-[10px] font-bold uppercase tracking-wider text-emerald-600">Money in</div>
                    <div className="mt-1.5 text-xl font-extrabold tabular-nums text-emerald-700">42,500</div>
                </div>
                <div className="rounded-xl border-rose-100 bg-rose-50 p-5">
                    <div className="text-[10px] font-bold uppercase tracking-wider text-rose-600">Money out</div>
                    <div className="mt-1.5 text-xl font-extrabold tabular-nums text-rose-700">31,240</div>
                </div>
            </div>
            <div className="mt-3.5 rounded-xl border-slate-200 bg-white p-5">
                <div className="flex items-center justify-between">
                    <span className="text-xs font-semibold text-slate-500">Pool balance</span>
                    <span className="text-base font-extrabold tabular-nums text-indigo-600">11,260</span>
                </div>
                <div className="mt-3.5 h-2.5 overflow-hidden rounded-full bg-slate-100">
                    <div className="h-full w-3/4 rounded-full bg-gradient-to-r from-indigo-500 to-sky-400" />
                </div>
                <p className="mt-3.5 text-[11px] text-slate-400">Every balance reconciles automatically as entries are recorded.</p>
            </div>
        </div>
    );
}
