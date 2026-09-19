import React, { useEffect, useState } from 'react';
import { Link } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import usePlatformBranding from '@/Utils/usePlatformBranding';
import { ArrowRight } from './LandingPrimitives';
import { NAV_LINKS } from './landingContent';

/**
 * Sticky landing header with a mobile drawer.
 *
 * Owns its own scroll + drawer state, so the page shell stays free of UI state
 * and the entrance animation of the hero is never blocked by header re-renders.
 */
export default function LandingHeader() {
    const { name, tagline, logoUrl } = usePlatformBranding();
    const [mobileNavOpen, setMobileNavOpen] = useState(false);
    const [scrolled, setScrolled] = useState(false);

    useEffect(() => {
        const onScroll = () => setScrolled(window.scrollY > 12);
        window.addEventListener('scroll', onScroll, { passive: true });
        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    return (
        <header
            className={`sticky top-0 z-50 border-b transition-all duration-300 ${scrolled
                    ? 'border-slate-200/80 bg-white/95 shadow-sm shadow-slate-900/5 backdrop-blur-xl'
                    : 'border-transparent bg-white/80 backdrop-blur-sm'
                }`}
        >
            <nav className="mx-auto flex max-w-7xl items-center justify-between gap-3 px-4 py-3.5 sm:px-6 lg:px-8">
                <Link href="/" className="group flex items-center gap-3">
                    {logoUrl ? (
                        <img
                            src={logoUrl}
                            alt={name}
                            className="h-9 w-9 rounded-xl object-contain shadow-sm ring-1 ring-slate-200/60 transition-transform group-hover:scale-105"
                        />
                    ) : (
                        <ApplicationLogo className="h-9 w-9 rounded-xl object-contain shadow-sm ring-1 ring-slate-200/60 transition-transform group-hover:scale-105" />
                    )}
                    <span className="flex flex-col leading-tight">
                        <span className="text-sm font-bold tracking-tight text-slate-900">{name}</span>
                        <span className="text-[11px] font-medium text-slate-400">{tagline}</span>
                    </span>
                </Link>

                <div className="hidden items-center gap-0.5 md:flex">
                    {NAV_LINKS.map(([href, label]) => (
                        <a
                            key={href}
                            href={href}
                            className="rounded-lg px-3.5 py-2 text-sm font-semibold text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900"
                        >
                            {label}
                        </a>
                    ))}
                    <Link
                        href={route('login')}
                        className="ml-1 rounded-lg px-3.5 py-2 text-sm font-semibold text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900"
                    >
                        Log in
                    </Link>
                    <Link
                        href={route('register')}
                        className="ml-1.5 inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-md shadow-indigo-600/25 transition-all hover:bg-indigo-700 hover:shadow-lg hover:shadow-indigo-600/30 active:scale-[.98]"
                    >
                        Get Started
                        <ArrowRight />
                    </Link>
                </div>

                <button
                    type="button"
                    onClick={() => setMobileNavOpen((o) => !o)}
                    aria-expanded={mobileNavOpen}
                    aria-label="Toggle navigation menu"
                    className="rounded-xl p-2.5 text-slate-600 transition-colors hover:bg-slate-100 md:hidden"
                >
                    <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        {mobileNavOpen ? (
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                        ) : (
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 6h16M4 12h16M4 18h16" />
                        )}
                    </svg>
                </button>
            </nav>

            {/* Mobile drawer */}
            <div
                className={`overflow-hidden border-t border-slate-200/70 bg-white transition-all duration-300 md:hidden ${mobileNavOpen ? 'max-h-96 opacity-100' : 'max-h-0 opacity-0'
                    }`}
            >
                <div className="flex flex-col gap-0.5 px-4 py-3">
                    {NAV_LINKS.map(([href, label]) => (
                        <a
                            key={href}
                            href={href}
                            onClick={() => setMobileNavOpen(false)}
                            className="rounded-xl px-3.5 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50"
                        >
                            {label}
                        </a>
                    ))}
                    <Link href={route('login')} className="rounded-xl px-3.5 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50">
                        Log in
                    </Link>
                    <Link
                        href={route('register')}
                        className="mt-1.5 rounded-xl bg-indigo-600 px-4 py-2.5 text-center text-sm font-semibold text-white shadow-md shadow-indigo-600/20 hover:bg-indigo-700"
                    >
                        Get Started
                    </Link>
                </div>
            </div>
        </header>
    );
}
