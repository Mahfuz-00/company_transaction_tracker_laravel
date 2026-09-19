import React from 'react';
import { INSTITUTION_TYPES } from './landingContent';

/** A quiet band of the institution types the platform serves. */
export default function InstitutionTypes() {
    return (
        <section className="border-y border-slate-100 bg-slate-50/50 py-14">
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <p className="text-center text-[11px] font-bold uppercase tracking-wider text-slate-400">
                    Built for any shared meal &amp; expense operation
                </p>
                <div className="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {INSTITUTION_TYPES.map((type) => (
                        <div
                            key={type.label}
                            className="group rounded-2xl border-slate-200/80 bg-white p-5 shadow-sm transition-all duration-300 hover:-translate-y-0.5 hover:border-indigo-200 hover:shadow-md hover:shadow-indigo-500/5"
                        >
                            <div className="mb-3 flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600 transition-colors group-hover:bg-indigo-600 group-hover:text-white">
                                <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.75" d={type.icon} />
                                </svg>
                            </div>
                            <div className="text-sm font-bold text-slate-800">{type.label}</div>
                            <div className="mt-1 text-xs leading-relaxed text-slate-500">{type.detail}</div>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}