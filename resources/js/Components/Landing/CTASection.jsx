import React from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import { ArrowRight, ContactField } from './LandingPrimitives';

/**
 * The closing call-to-action + lead-capture form.
 *
 * Captures demo requests through POST /contact. Owns its own form state so the
 * page shell stays declarative.
 */
export default function CTASection() {
    const { props } = usePage();
    const flash = props?.flash || {};

    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        institution_name: '',
        institution_type: '',
        message: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('landing.contact'), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    const perks = ['Set up in minutes', 'Strict tenant isolation', 'Free 7-day trial'];

    return (
        <section id="contact" className="scroll-mt-24 py-24">
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div className="overflow-hidden rounded-3xl bg-gradient-to-br from-indigo-600 via-indigo-700 to-slate-900 shadow-2xl shadow-indigo-600/20">
                    <div className="grid gap-0 lg:grid-cols-2">
                        {/* Copy */}
                        <div className="p-8 sm:p-12 lg:p-14">
                            <h2 className="text-3xl font-extrabold tracking-tight text-white sm:text-4xl">
                                Stop reconciling by hand
                            </h2>
                            <p className="mt-4 max-w-md text-base leading-relaxed text-indigo-100">
                                Request a personalised demo, or create your account now and bring your institution&apos;s
                                meals, contributions and expenses into one clear, trustworthy ledger.
                            </p>

                            <ul className="mt-9 space-y-3">
                                {perks.map((t) => (
                                    <li key={t} className="flex items-center gap-3 text-sm font-medium text-indigo-50">
                                        <span className="flex h-7 w-7 items-center justify-center rounded-full bg-white/15 ring-1 ring-white/20">
                                            <svg className="h-3.5 w-3.5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M5 13l4 4L19 7" />
                                            </svg>
                                        </span>
                                        {t}
                                    </li>
                                ))}
                            </ul>

                            <div className="mt-10 flex flex-col gap-3 sm:flex-row">
                                <Link
                                    href={route('register')}
                                    className="inline-flex items-center justify-center gap-2 rounded-xl bg-white px-6 py-3.5 text-sm font-bold text-indigo-700 shadow-lg transition-all hover:bg-indigo-50 active:scale-[.98]"
                                >
                                    Get Started Free
                                    <ArrowRight />
                                </Link>
                                <Link
                                    href={route('login')}
                                    className="inline-flex items-center justify-center rounded-xl border-white/25 bg-white/10 px-6 py-3.5 text-sm font-bold text-white backdrop-blur transition-colors hover:bg-white/20"
                                >
                                    I have an account
                                </Link>
                            </div>
                        </div>

                        {/* Form */}
                        <div className="border-t border-white/10 bg-white/5 p-8 backdrop-blur sm:p-12 lg:border-l lg:border-t-0 lg:p-14">
                            <h3 className="text-lg font-bold text-white">Request a demo</h3>
                            <p className="mt-1.5 text-xs text-indigo-200">We&apos;ll reach out within one business day.</p>

                            {flash.success && (
                                <div className="mt-5 rounded-xl border-emerald-300/40 bg-emerald-500/20 px-4 py-3 text-sm text-white">
                                    {flash.success}
                                </div>
                            )}

                            <form onSubmit={submit} className="mt-6 space-y-4">
                                <ContactField error={errors.name}>
                                    <input
                                        type="text"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="Your name"
                                        className="w-full rounded-xl border-white/15 bg-white/10 px-4 py-3 text-sm text-white placeholder-indigo-200/60 outline-none transition-all focus:border-white/40 focus:bg-white/15 focus:ring-2 focus:ring-white/10"
                                    />
                                </ContactField>

                                <ContactField error={errors.email}>
                                    <input
                                        type="email"
                                        value={data.email}
                                        onChange={(e) => setData('email', e.target.value)}
                                        placeholder="Work email"
                                        className="w-full rounded-xl border-white/15 bg-white/10 px-4 py-3 text-sm text-white placeholder-indigo-200/60 outline-none transition-all focus:border-white/40 focus:bg-white/15 focus:ring-2 focus:ring-white/10"
                                    />
                                </ContactField>

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <ContactField error={errors.institution_name}>
                                        <input
                                            type="text"
                                            value={data.institution_name}
                                            onChange={(e) => setData('institution_name', e.target.value)}
                                            placeholder="Institution name"
                                            className="w-full rounded-xl border-white/15 bg-white/10 px-4 py-3 text-sm text-white placeholder-indigo-200/60 outline-none transition-all focus:border-white/40 focus:bg-white/15 focus:ring-2 focus:ring-white/10"
                                        />
                                    </ContactField>
                                    <ContactField error={errors.institution_type}>
                                        <select
                                            value={data.institution_type}
                                            onChange={(e) => setData('institution_type', e.target.value)}
                                            className="w-full rounded-xl border-white/15 bg-white/10 px-4 py-3 text-sm text-white outline-none transition-all focus:border-white/40 focus:bg-white/15 focus:ring-2 focus:ring-white/10"
                                        >
                                            <option value="" className="text-slate-900">Type of institution</option>
                                            <option value="corporate" className="text-slate-900">Corporate office</option>
                                            <option value="university" className="text-slate-900">University hall</option>
                                            <option value="college" className="text-slate-900">College / hostel</option>
                                            <option value="mess" className="text-slate-900">General mess</option>
                                        </select>
                                    </ContactField>
                                </div>

                                <ContactField error={errors.message}>
                                    <textarea
                                        rows={3}
                                        value={data.message}
                                        onChange={(e) => setData('message', e.target.value)}
                                        placeholder="Tell us about your needs (optional)"
                                        className="w-full resize-none rounded-xl border-white/15 bg-white/10 px-4 py-3 text-sm text-white placeholder-indigo-200/60 outline-none transition-all focus:border-white/40 focus:bg-white/15 focus:ring-2 focus:ring-white/10"
                                    />
                                </ContactField>

                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-white px-6 py-3.5 text-sm font-bold text-indigo-700 shadow-lg transition-all hover:bg-indigo-50 active:scale-[.98] disabled:opacity-60"
                                >
                                    {processing ? 'Sending...' : 'Request demo'}
                                    {!processing && <ArrowRight />}
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    );
}
