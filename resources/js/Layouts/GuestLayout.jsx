import ApplicationLogo from '@/Components/ApplicationLogo';
import ThemeProvider from '@/Components/ThemeProvider';
import usePlatformBranding from '@/Utils/usePlatformBranding';
import { Link } from '@inertiajs/react';

/**
 * Guest layout for the unauthenticated screens (login, register, password
 * reset/confirm, email verification, invitation acceptance).
 *
 * WHY IT IS WRAPPED IN ThemeProvider:
 * ThemeProvider is otherwise mounted only by AuthenticatedLayout, which meant
 * these pages received the global CSS variables but NOT the live React theme
 * context - so `useTheme()` / themed components on the auth screens silently
 * fell back to the defaults. Wrapping here gives the guest screens the same
 * live theme as the rest of the app, so a theme change (or the global toggle)
 * repaints them in the same instant.
 */
export default function GuestLayout({ heading, subheading, children }) {
    const { name, tagline, logoUrl } = usePlatformBranding();

    return (
        <ThemeProvider>
        <div className="relative min-h-screen flex flex-col items-center justify-center bg-slate-50/50 px-4 py-12 selection:bg-indigo-500 selection:text-white">

            {/* Background Ambience / Glow Elements Matching Landing Page */}
            <div aria-hidden="true" className="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(ellipse_80%_60%_at_50%_-20%,rgba(99,102,241,0.12),transparent)]" />
            <div aria-hidden="true" className="pointer-events-none absolute -top-32 right-0 -z-10 h-96 w-96 rounded-full bg-indigo-300/20 blur-3xl" />
            <div aria-hidden="true" className="pointer-events-none absolute top-40 -left-20 -z-10 h-80 w-80 rounded-full bg-sky-300/15 blur-3xl" />

            {/* Main Content Card matching the modern component card aesthetics */}
            <div className="relative z-10 w-full sm:max-w-md rounded-3xl border border-slate-200/80 bg-white p-8 shadow-xl shadow-slate-900/5 ring-1 ring-slate-900/5">

                {/* Brand Logo Header */}
                <div className="flex justify-center mb-6">
                    <Link href="/" className="group flex items-center gap-3">
                        {logoUrl ? (
                            <img
                                src={logoUrl}
                                alt={name}
                                className="h-10 w-10 rounded-xl object-contain shadow-sm ring-1 ring-slate-200/60 transition-transform group-hover:scale-105"
                            />
                        ) : (
                            <ApplicationLogo className="h-10 w-10 rounded-xl object-contain shadow-sm ring-1 ring-slate-200/60 transition-transform group-hover:scale-105" />
                        )}
                    </Link>
                </div>

                {heading && (
                    <div className="mb-6 text-center">
                        <h1 className="text-xl font-bold tracking-tight text-slate-900">{heading}</h1>
                        {subheading && <p className="mt-1.5 text-xs text-slate-500">{subheading}</p>}
                    </div>
                )}

                {children}
            </div>

            {/* Footer Copyright Link */}
            <div className="mt-8 text-center">
                <p className="text-xs font-medium text-slate-400">
                    &copy; {new Date().getFullYear()} {name} — Secure Multi-tenant Ledger.
                </p>
            </div>
        </div>
        </ThemeProvider>
    );
}
