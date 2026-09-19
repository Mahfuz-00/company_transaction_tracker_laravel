import React from 'react';
import { Link } from '@inertiajs/react';
import { ArrowRight, SectionHeading } from './LandingPrimitives';
import { formatMoney } from './landingContent';

/**
 * Pricing tiers, rendered from the plans the Software Super Admin configured.
 *
 * The second plan (when there are at least two) is highlighted as the popular
 * choice. Empty state points visitors to the contact section.
 */
export default function PricingGrid({ plans = [] }) {
    return (
        <section id="pricing" className="scroll-mt-24 border-t border-slate-100 py-24">
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <SectionHeading
                    eyebrow="Transparent pricing"
                    title="Plans that scale with your institution"
                    body="Start free, upgrade when you grow. Every plan includes strict multi-tenant isolation and the full meal & expense engine."
                />

                {plans.length > 0 ? (
                    <div className="mt-16 grid items-stretch gap-6 lg:grid-cols-3">
                        {plans.map((plan, i) => {
                            const featured = i === Math.min(1, plans.length - 1) && plans.length > 1;
                            return (
                                <div
                                    key={plan.id}
                                    className={`wa-rise wa-delay-${(i % 3) + 1} relative flex flex-col rounded-3xl border p-8 transition-all duration-300 hover:-translate-y-1.5 ${featured
                                            ? 'border-indigo-400 bg-gradient-to-b from-indigo-600 to-indigo-700 text-white shadow-2xl shadow-indigo-600/30'
                                            : 'border-slate-200/80 bg-white shadow-sm hover:border-slate-300 hover:shadow-xl'
                                        }`}
                                >
                                    {featured && (
                                        <span className="absolute -top-3.5 left-1/2 -translate-x-1/2 rounded-full bg-white px-3.5 py-1 text-[10px] font-bold uppercase tracking-wider text-indigo-700 shadow-md">
                                            Most popular
                                        </span>
                                    )}

                                    <h3 className={`text-lg font-bold ${featured ? 'text-white' : 'text-slate-900'}`}>{plan.name}</h3>
                                    <p className={`mt-1.5 text-xs leading-relaxed ${featured ? 'text-indigo-100' : 'text-slate-500'}`}>{plan.description}</p>

                                    <div className="mt-6 flex items-end gap-1.5">
                                        <span className={`text-4xl font-extrabold tracking-tight ${featured ? 'text-white' : 'text-slate-900'}`}>
                                            {plan.is_free ? 'Free' : formatMoney(plan.monthly_price)}
                                        </span>
                                        {!plan.is_free && (
                                            <span className={`mb-1.5 text-sm ${featured ? 'text-indigo-200' : 'text-slate-400'}`}>/month</span>
                                        )}
                                    </div>

                                    <ul className="mt-7 flex-1 space-y-3">
                                        {(plan.features || []).map((f, j) => (
                                            <li key={j} className={`flex items-start gap-2.5 text-sm ${featured ? 'text-indigo-50' : 'text-slate-600'}`}>
                                                <svg className={`mt-0.5 h-4 w-4 flex-shrink-0 ${featured ? 'text-white' : 'text-emerald-500'}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M5 13l4 4L19 7" />
                                                </svg>
                                                {f}
                                            </li>
                                        ))}
                                        <li className={`flex items-start gap-2.5 text-sm ${featured ? 'text-indigo-50' : 'text-slate-600'}`}>
                                            <svg className={`mt-0.5 h-4 w-4 flex-shrink-0 ${featured ? 'text-white' : 'text-emerald-500'}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M5 13l4 4L19 7" />
                                            </svg>
                                            Up to {plan.member_limit_label} members
                                        </li>
                                    </ul>

                                    <Link
                                        href={route('register')}
                                        className={`mt-8 inline-flex items-center justify-center gap-2 rounded-xl px-5 py-3.5 text-sm font-bold transition-all active:scale-[.98] ${featured ? 'bg-white text-indigo-700 hover:bg-indigo-50' : 'bg-indigo-600 text-white hover:bg-indigo-700'
                                            }`}
                                    >
                                        {plan.is_free ? 'Start free' : 'Choose plan'}
                                        <ArrowRight />
                                    </Link>
                                </div>
                            );
                        })}
                    </div>
                ) : (
                    <p className="mt-14 text-center text-sm text-slate-500">
                        Pricing tiers are being finalised —{' '}
                        <a href="#contact" className="font-semibold text-indigo-600 underline-offset-2 hover:underline">
                            contact us
                        </a>{' '}
                        for a quote.
                    </p>
                )}
            </div>
        </section>
    );
}
