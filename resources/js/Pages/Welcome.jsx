import React, { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';

const FEATURES = [
    {
        title: 'Multi-Institution Ready',
        body: 'Run an office canteen, a university hall, or a general mess on the same platform. Each institution keeps its own roster, ledger and branding, fully isolated.',
        icon: 'M3 21h18M4 10h16M5 10V21M19 10V21M9 21v-7M15 21v-7M12 3l9 6H3l9-6z',
    },
    {
        title: 'Pooled Contributions',
        body: 'Every member contributes to a shared fund. Track each payment against the group balance so nobody over- or under-pays.',
        icon: 'M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    },
    {
        title: 'Meal Count Tracking',
        body: 'Log breakfast, lunch and dinner per participant, per day. Meal rates turn those counts into exact, fair costs automatically.',
        icon: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4',
    },
    {
        title: 'Expense & Vendor Log',
        body: 'Record purchases, rent, and utilities against the vendors you buy from. See total spending against total contributions, always in balance.',
        icon: 'M20 12V8H6a2 2 0 010-4h12v4m0 4v4H6a2 2 0 000 4h12v-4m0-4h-4a2 2 0 000 4h4v-4z',
    },
    {
        title: 'Personal Member Dashboard',
        body: 'Every participant gets a private view of their own deposits, meal history and live balance - plus the manager overseeing their account.',
        icon: 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
    },
    {
        title: 'Claims & Disputes',
        body: 'Members can flag a missing deposit or meal, or claim an out-of-pocket purchase. Managers approve, and balances adjust automatically.',
        icon: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
    },
    {
        title: 'Reports & Analytics',
        body: 'Daily, weekly, and monthly breakdowns of meals served and money spent, so any manager can report with confidence.',
        icon: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
    },
    {
        title: 'Roles & Permissions',
        body: 'Platform super admins, institution admins, meal managers and members each see exactly what they should - scoped to their own institution.',
        icon: 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-6.93 4 4 0 004 6.93z',
    },
];

const STEPS = [
    { step: '01', title: 'Set up your institution', body: 'Create your workspace in seconds - an office canteen, a university hall, or a general mess. Each gets its own roster and ledger.' },
    { step: '02', title: 'Add members and managers', body: 'Managers add participants, organise them into departments or teams, and define the per-meal rate for the month.' },
    { step: '03', title: 'Log contributions and meals', body: 'Money in from each member and daily meal counts are recorded as they happen - entries take seconds.' },
    { step: '04', title: 'Track balances live', body: 'Expenses are logged against vendors, and every member balance updates automatically. Members can raise claims any time.' },
];

const PREVIEW_ROWS = [
    { name: 'Farhan Hossain', meals: 62, due: '1,240', status: 'Paid', tone: 'bg-emerald-50 text-emerald-700 border-emerald-100' },
    { name: 'Rakib Ahmed', meals: 58, due: '460', status: 'Partial', tone: 'bg-amber-50 text-amber-700 border-amber-100' },
    { name: 'Tamim Iqbal', meals: 60, due: '1,200', status: 'Paid', tone: 'bg-emerald-50 text-emerald-700 border-emerald-100' },
    { name: 'Sadia Afrin', meals: 44, due: '880', status: 'Due', tone: 'bg-rose-50 text-rose-700 border-rose-100' },
];

/* The institution types the platform serves, shown as a trust strip. */
const INSTITUTION_TYPES = [
    { label: 'Corporate Offices', detail: 'Staff cafeterias & office meal programs' },
    { label: 'University Halls', detail: 'Residential halls & shared messes' },
    { label: 'General Messes', detail: 'Hostels, clubs & shared households' },
    { label: 'Colleges & Hostels', detail: 'Campus boarding & dining' },
];

const PREVIEW_CARDS = [
    { label: 'Collected', value: '42,500', tone: 'text-emerald-600', bg: 'bg-emerald-50' },
    { label: 'Spent', value: '31,240', tone: 'text-rose-600', bg: 'bg-rose-50' },
    { label: 'Balance', value: '11,260', tone: 'text-indigo-600', bg: 'bg-indigo-50' },
];

export default function Welcome() {
    const [mobileNavOpen, setMobileNavOpen] = useState(false);

    return (
        <>
            <Head title="Meal & Expense Management System for Offices, Halls & Messes" />

            <div className="min-h-screen bg-white text-slate-900 antialiased">
                <header className="sticky top-0 z-40 border-b border-slate-200/70 bg-white/85 backdrop-blur-md">
                    <nav className="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3.5 sm:px-6 lg:px-8">
                        <Link href="/" className="flex items-center gap-3">
                            <ApplicationLogo className="h-9 w-9 rounded-lg object-contain" />
                            <span className="flex flex-col leading-tight">
                                <span className="text-sm font-bold text-slate-900">Meal &amp; Expense Manager</span>
                                <span className="text-[11px] font-medium text-slate-400">Multi-institution platform</span>
                            </span>
                        </Link>

                        <div className="hidden items-center gap-1 md:flex">
                            <a href="#features" className="rounded-lg px-3.5 py-2 text-sm font-semibold text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900">Features</a>
                            <a href="#how-it-works" className="rounded-lg px-3.5 py-2 text-sm font-semibold text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900">How It Works</a>
                            <Link href={route('login')} className="rounded-lg px-3.5 py-2 text-sm font-semibold text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900">Log In</Link>
                            <Link href={route('register')} className="ml-1 inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700 active:bg-indigo-800">
                                Get Started
                                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13 7l5 5m0 0l-5 5m5-5H6" /></svg>
                            </Link>
                        </div>

                        <button type="button" onClick={() => setMobileNavOpen((open) => !open)} aria-expanded={mobileNavOpen} aria-label="Toggle navigation menu" className="rounded-lg p-2 text-slate-600 transition-colors hover:bg-slate-100 md:hidden">
                            <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                {mobileNavOpen
                                    ? <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                                    : <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 6h16M4 12h16M4 18h16" />}
                            </svg>
                        </button>
                    </nav>

                    {mobileNavOpen && (
                        <div className="border-t border-slate-200/70 bg-white px-4 py-3 md:hidden">
                            <div className="flex flex-col gap-1">
                                <a href="#features" onClick={() => setMobileNavOpen(false)} className="rounded-lg px-3.5 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-100">Features</a>
                                <a href="#how-it-works" onClick={() => setMobileNavOpen(false)} className="rounded-lg px-3.5 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-100">How It Works</a>
                                <Link href={route('login')} className="rounded-lg px-3.5 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-100">Log In</Link>
                                <Link href={route('register')} className="mt-1 rounded-lg bg-indigo-600 px-4 py-2.5 text-center text-sm font-semibold text-white hover:bg-indigo-700">Get Started</Link>
                            </div>
                        </div>
                    )}
                </header>

                <section className="relative overflow-hidden">
                    <div aria-hidden="true" className="pointer-events-none absolute inset-0 -z-10 bg-gradient-to-b from-indigo-50/80 via-white to-white" />
                    <div aria-hidden="true" className="pointer-events-none absolute -top-24 right-0 -z-10 h-72 w-72 rounded-full bg-indigo-200/40 blur-3xl" />
                    <div aria-hidden="true" className="pointer-events-none absolute top-32 -left-16 -z-10 h-72 w-72 rounded-full bg-sky-200/40 blur-3xl" />

                    <div className="mx-auto max-w-7xl px-4 pb-20 pt-16 sm:px-6 sm:pt-24 lg:px-8">
                        <div className="grid items-center gap-14 lg:grid-cols-2 lg:gap-10">
                            <div className="max-w-xl lg:max-w-none">
                                <span className="inline-flex items-center gap-2 rounded-full border-indigo-100 bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700">
                                    <span className="h-1.5 w-1.5 rounded-full bg-indigo-500" />
                                    Multi-institution meal &amp; expense management
                                </span>

                                <h1 className="mt-5 text-4xl font-extrabold leading-[1.1] tracking-tight text-slate-900 sm:text-5xl lg:text-6xl">
                                    One system for every
                                    <span className="block bg-gradient-to-r from-indigo-600 to-sky-500 bg-clip-text text-transparent">shared meal &amp; expense ledger</span>
                                </h1>

                                <p className="mt-5 text-base leading-relaxed text-slate-600 sm:text-lg">
                                    Run meal programs for offices, university halls, colleges and general messes on a single platform. Track the money members pool, the meals they eat, and every expense - turning a handwritten register into a live, always-balanced ledger.
                                </p>

                                <div className="mt-8 flex flex-col gap-3 sm:flex-row">
                                    <Link href={route('register')} className="inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-600 px-6 py-3.5 text-sm font-bold text-white shadow-lg shadow-indigo-600/20 transition-all hover:bg-indigo-700 hover:shadow-indigo-600/30 active:bg-indigo-800">
                                        Create Free Account
                                        <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13 7l5 5m0 0l-5 5m5-5H6" /></svg>
                                    </Link>
                                    <Link href={route('login')} className="inline-flex items-center justify-center gap-2 rounded-xl border-slate-300 bg-white px-6 py-3.5 text-sm font-bold text-slate-700 shadow-sm transition-colors hover:bg-slate-50 hover:text-slate-900">Sign In</Link>
                                </div>

                                <dl className="mt-10 grid grid-cols-3 gap-6 border-t border-slate-200 pt-6">
                                    {[
                                        { label: 'Meals tracked', value: 'B / L / D' },
                                        { label: 'Ledger entries', value: 'Per member' },
                                        { label: 'Institutions', value: 'Many, isolated' },
                                    ].map((item) => (
                                        <div key={item.label}>
                                            <dt className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">{item.label}</dt>
                                            <dd className="mt-1 text-sm font-bold text-slate-800">{item.value}</dd>
                                        </div>
                                    ))}
                                </dl>
                            </div>

                            <div className="relative">
                                <div className="absolute -inset-4 -z-10 rounded-3xl bg-gradient-to-tr from-indigo-100 via-white to-sky-100 opacity-70 blur-xl" />

                                <div className="overflow-hidden rounded-2xl border-slate-200 bg-white shadow-2xl shadow-slate-900/10">
                                    <div className="flex items-center gap-2 border-b border-slate-100 bg-slate-50 px-4 py-3">
                                        <span className="h-2.5 w-2.5 rounded-full bg-rose-400" />
                                        <span className="h-2.5 w-2.5 rounded-full bg-amber-400" />
                                        <span className="h-2.5 w-2.5 rounded-full bg-emerald-400" />
                                        <span className="ml-3 text-[11px] font-semibold text-slate-400">Meal Report - November</span>
                                    </div>

                                    <div className="space-y-5 p-5">
                                        <div className="grid grid-cols-3 gap-3">
                                            {PREVIEW_CARDS.map((card) => (
                                                <div key={card.label} className={`rounded-xl ${card.bg} p-3`}>
                                                    <div className="text-[10px] font-semibold uppercase tracking-wider text-slate-500">{card.label}</div>
                                                    <div className={`mt-1 text-sm font-bold ${card.tone}`}>{card.value}</div>
                                                </div>
                                            ))}
                                        </div>

                                        <div className="space-y-2.5">
                                            {PREVIEW_ROWS.map((row) => (
                                                <div key={row.name} className="flex items-center gap-3 rounded-xl border-slate-100 bg-white px-3 py-2.5">
                                                    <div className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-indigo-600 text-[10px] font-bold text-white">
                                                        {row.name.split(' ').map((part) => part[0]).slice(0, 2).join('')}
                                                    </div>
                                                    <div className="min-w-0 flex-1">
                                                        <div className="truncate text-xs font-semibold text-slate-800">{row.name}</div>
                                                        <div className="text-[10px] font-medium text-slate-400">{row.meals} meals this month</div>
                                                    </div>
                                                    <div className="text-right">
                                                        <div className="text-xs font-bold text-slate-800">{row.due}</div>
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

                {/* Who the platform is for. Makes the multi-institution scope
                    obvious before the feature grid. */}
                <section className="border-t border-slate-100 bg-white py-12">
                    <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                        <p className="text-center text-[11px] font-bold uppercase tracking-wider text-slate-400">
                            Built for any shared meal &amp; expense operation
                        </p>
                        <div className="mt-6 grid-cols-2 gap-4 lg:grid-cols-4">
                            {INSTITUTION_TYPES.map((type) => (
                                <div key={type.label} className="rounded-xl border-slate-200 bg-slate-50/60 p-4">
                                    <div className="text-sm font-bold text-slate-800">{type.label}</div>
                                    <div className="mt-0.5 text-xs leading-relaxed text-slate-500">{type.detail}</div>
                                </div>
                            ))}
                        </div>
                    </div>
                </section>

                <section id="features" className="scroll-mt-20 border-t border-slate-100 bg-slate-50/70 py-20">
                    <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                        <div className="mx-auto max-w-2xl text-center">
                            <h2 className="text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">Everything a meal program juggles, in one place</h2>
                            <p className="mt-4 text-base leading-relaxed text-slate-600">From the first contribution to the month-end report, each part of the money and meal cycle is recorded once and reused everywhere - across every institution you run.</p>
                        </div>

                        <div className="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                            {FEATURES.map((feature) => (
                                <div key={feature.title} className="group rounded-2xl border-slate-200 bg-white p-6 shadow-sm transition-all hover:-translate-y-0.5 hover:border-indigo-200 hover:shadow-lg hover:shadow-indigo-600/5">
                                    <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600 transition-colors group-hover:bg-indigo-600 group-hover:text-white">
                                        <svg className="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" d={feature.icon} /></svg>
                                    </div>
                                    <h3 className="mt-4 text-base font-bold text-slate-900">{feature.title}</h3>
                                    <p className="mt-2 text-sm leading-relaxed text-slate-600">{feature.body}</p>
                                </div>
                            ))}
                        </div>
                    </div>
                </section>

                <section id="how-it-works" className="scroll-mt-20 py-20">
                    <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                        <div className="mx-auto max-w-2xl text-center">
                            <h2 className="text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">Up and running in four steps</h2>
                            <p className="mt-4 text-base leading-relaxed text-slate-600">No accounting background needed - the platform handles the arithmetic, you just record what happened.</p>
                        </div>

                        <ol className="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                            {STEPS.map((item) => (
                                <li key={item.step} className="relative rounded-2xl border-slate-200 bg-white p-6 shadow-sm">
                                    <span className="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-slate-900 text-xs font-bold text-white">{item.step}</span>
                                    <h3 className="mt-4 text-base font-bold text-slate-900">{item.title}</h3>
                                    <p className="mt-2 text-sm leading-relaxed text-slate-600">{item.body}</p>
                                </li>
                            ))}
                        </ol>
                    </div>
                </section>

                <section className="px-4 pb-20 sm:px-6 lg:px-8">
                    <div className="mx-auto max-w-7xl overflow-hidden rounded-3xl bg-gradient-to-br from-indigo-600 via-indigo-700 to-slate-900 px-6 py-14 shadow-2xl shadow-indigo-600/20 sm:px-12 sm:py-16">
                        <div className="mx-auto max-w-2xl text-center">
                            <h2 className="text-3xl font-extrabold tracking-tight text-white sm:text-4xl">Stop reconciling by hand</h2>
                            <p className="mt-4 text-base leading-relaxed text-indigo-100">Create your account and bring your institution's meals, contributions, and expenses into one clear, trustworthy ledger.</p>
                            <div className="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                                <Link href={route('register')} className="inline-flex items-center justify-center gap-2 rounded-xl bg-white px-6 py-3.5 text-sm font-bold text-indigo-700 shadow-lg transition-all hover:bg-indigo-50 active:bg-indigo-100">
                                    Get Started - It's Free
                                    <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13 7l5 5m0 0l-5 5m5-5H6" /></svg>
                                </Link>
                                <Link href={route('login')} className="inline-flex items-center justify-center rounded-xl border-white/25 bg-white/10 px-6 py-3.5 text-sm font-bold text-white backdrop-blur transition-colors hover:bg-white/20">I Already Have an Account</Link>
                            </div>
                        </div>
                    </div>
                </section>

                <footer className="border-t border-slate-200 bg-slate-50">
                    <div className="mx-auto flex max-w-7xl flex-col items-center justify-between gap-4 px-4 py-8 sm:flex-row sm:px-6 lg:px-8">
                        <div className="flex items-center gap-2.5">
                            <ApplicationLogo className="h-7 w-7 rounded-md object-contain" />
                            <span className="text-sm font-bold text-slate-800">Meal &amp; Expense Manager</span>
                        </div>

                        <p className="text-xs font-medium text-slate-500">&copy; {new Date().getFullYear()} Meal &amp; Expense Manager. Multi-institution, for offices, halls &amp; messes.</p>

                        <div className="flex items-center gap-5">
                            <Link href={route('login')} className="text-xs font-semibold text-slate-500 transition-colors hover:text-slate-900">Log In</Link>
                            <Link href={route('register')} className="text-xs font-semibold text-slate-500 transition-colors hover:text-slate-900">Register</Link>
                        </div>
                    </div>
                </footer>
            </div>
        </>
    );
}
