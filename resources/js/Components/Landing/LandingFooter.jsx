import React from 'react';
import { Link } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import usePlatformBranding from '@/Utils/usePlatformBranding';

/** Landing footer: brand, copyright and the two account entry points. */
export default function LandingFooter() {
    const { name } = usePlatformBranding();

    return (
        <footer className="mt-20 border-t border-slate-200 pt-10 pb-16">
            <div className="mx-auto flex max-w-7xl flex-col items-center justify-between gap-5 px-4 sm:flex-row sm:px-6 lg:px-8">
                <div className="flex items-center gap-3">
                    <ApplicationLogo className="h-8 w-8 rounded-lg object-contain ring-1 ring-slate-200/60" />
                    <span className="text-sm font-bold text-slate-800">{name}</span>
                </div>
                <p className="text-xs font-medium text-slate-500">
                    &copy; {new Date().getFullYear()} — Multi-institution, for offices, halls &amp; messes.
                </p>
                <div className="flex items-center gap-6">
                    <Link href={route('login')} className="text-xs font-semibold text-slate-500 transition-colors hover:text-slate-900">
                        Log in
                    </Link>
                    <Link href={route('register')} className="text-xs font-semibold text-slate-500 transition-colors hover:text-slate-900">
                        Register
                    </Link>
                </div>
            </div>
        </footer>
    );
}
