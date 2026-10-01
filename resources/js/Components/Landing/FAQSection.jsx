import React, { useState } from 'react';
import { SectionHeading } from './LandingPrimitives';

const FAQS = [
    {
        q: 'How does the automated per-meal rate formula work?',
        a: 'The meal price is computed strictly as: (Total Expenses − Total Subsidies) ÷ Total Consumed Meals. There is no guesswork or artificial markup—members pay exactly what was spent minus applicable subsidies.',
    },
    {
        q: 'Can members schedule meals in advance or report absences?',
        a: 'Yes! Members can submit advance meal schedule notices (one-time, daily, weekly, or at custom intervals) telling meal managers whether they will eat or be absent to avoid wrongful meal count disputes.',
    },
    {
        q: 'How does paid institution registration and payment work?',
        a: 'When an institution subscribes, an admin account and workspace are initialized. The admin is securely redirected to the payment gateway. To protect against duplicates, incomplete signups can be resumed directly.',
    },
    {
        q: 'What languages does the platform support?',
        a: 'The platform offers multilingual interface support including English and Bengali (বাংলা), fully configurable from User Settings with instant UI updates.',
    },
    {
        q: 'How does the Autonomous AI Assistant operate?',
        a: 'The AI assistant uses Vector Database Retrieval-Augmented Generation (RAG) over ingested documentation and system knowledge. Questions that fall outside known documentation are automatically escalated to the Software Super Admin (SSA).',
    },
];

export default function FAQSection() {
    const [openIdx, setOpenIdx] = useState(null);

    const toggle = (idx) => {
        setOpenIdx(openIdx === idx ? null : idx);
    };

    return (
        <section id="faq" className="scroll-mt-24 border-t border-slate-100 py-24 bg-slate-50/50">
            <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                <SectionHeading
                    eyebrow="Frequently Asked Questions"
                    title="Everything you need to know"
                    body="Quick answers about multi-institution meal accounting, AI forecasting, and member scheduling."
                />

                <div className="mt-12 space-y-4" data-testid="onboarding-faq-accordion">
                    {FAQS.map((faq, idx) => {
                        const isOpen = openIdx === idx;
                        return (
                            <div
                                key={idx}
                                className="rounded-2xl border border-slate-200/80 bg-white shadow-xs overflow-hidden transition-all"
                            >
                                <button
                                    type="button"
                                    onClick={() => toggle(idx)}
                                    data-testid={`faq-toggle-${idx}`}
                                    className="w-full flex items-center justify-between p-5 text-left text-sm font-bold text-slate-800 hover:text-indigo-600 transition-colors"
                                >
                                    <span>{faq.q}</span>
                                    <svg
                                        className={`h-5 w-5 flex-shrink-0 text-slate-400 transform transition-transform ${isOpen ? 'rotate-180 text-indigo-600' : ''}`}
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 9l-7 7-7-7" />
                                    </svg>
                                </button>
                                {isOpen && (
                                    <div className="px-5 pb-5 text-xs leading-relaxed text-slate-600 border-t border-slate-100 pt-3" data-testid={`faq-answer-${idx}`}>
                                        {faq.a}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
            </div>
        </section>
    );
}
