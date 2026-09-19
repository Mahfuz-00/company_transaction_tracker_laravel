import React from 'react';
import { SectionHeading } from './LandingPrimitives';
import { FEATURES, TONE } from './landingContent';

/**
 * Animated feature cards. Each card rises in on load (`wa-rise wa-delay-N`) and
 * lifts on hover, using the shared tone palette.
 */
export default function FeatureShowcase() {
    return (
        <section id="features" className="scroll-mt-24 py-24">
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <SectionHeading
                    eyebrow="Why teams choose us"
                    title="Everything a meal program juggles, in one place"
                    body="From the first contribution to the month-end report, each part of the money and meal cycle is recorded once and reused everywhere — across every institution you run."
                />

                <div className="mt-16 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    {FEATURES.map((feature, i) => (
                        <div
                            key={feature.title}
                            className={`wa-rise wa-delay-${(i % 4) + 1} group relative rounded-2xl border-slate-200/80 bg-white p-7 shadow-sm transition-all duration-300 hover:-translate-y-1.5 hover:border-slate-300 hover:shadow-xl ${TONE[feature.tone].glow}`}
                        >
                            <div className={`flex h-12 w-12 items-center justify-center rounded-xl transition-all duration-300 ${TONE[feature.tone].chip} ${TONE[feature.tone].ring} group-hover:text-white group-hover:shadow-md`}>
                                <svg className="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" d={feature.icon} />
                                </svg>
                            </div>
                            <h3 className="mt-5 text-base font-bold text-slate-900">{feature.title}</h3>
                            <p className="mt-2.5 text-sm leading-relaxed text-slate-600">{feature.body}</p>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}
