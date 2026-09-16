import ApplicationLogo from '@/Components/ApplicationLogo';
import useTerminology from '@/Utils/useTerminology';
import { Link } from '@inertiajs/react';

export default function GuestLayout({ children, heading, subheading }) {
    const { institution } = useTerminology();

    return (
        <div className="min-h-screen bg-gradient-to-b from-indigo-50/80 via-white to-white">
            <div className="mx-auto flex min-h-screen w-full max-w-7xl flex-col px-4 py-8 sm:px-6 lg:px-8">
                {/* Top bar */}
                <header className="flex items-center justify-between">
                    <Link href="/" className="flex items-center gap-3">
                        <ApplicationLogo className="h-9 w-9 rounded-lg object-contain" />
                        <span className="flex flex-col leading-tight">
                            <span className="text-sm font-bold text-slate-900">
                                {institution?.name || 'Meal Manager'}
                            </span>
                            <span className="text-[11px] font-medium text-slate-400">
                                Shared meals, tracked
                            </span>
                        </span>
                    </Link>

                    <Link
                        href="/"
                        className="rounded-lg px-3.5 py-2 text-sm font-semibold text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900"
                    >
                        &larr; Back to home
                    </Link>
                </header>

                {/* Centered card */}
                <div className="flex flex-1 items-center justify-center py-10">
                    <div className="w-full max-w-md">
                        {(heading || subheading) && (
                            <div className="mb-6 text-center">
                                {heading && (
                                    <h1 className="text-2xl font-extrabold tracking-tight text-slate-900">
                                        {heading}
                                    </h1>
                                )}
                                {subheading && (
                                    <p className="mt-2 text-sm leading-relaxed text-slate-500">
                                        {subheading}
                                    </p>
                                )}
                            </div>
                        )}

                        <div className="rounded-2xl border-slate-200 bg-white p-7 shadow-xl shadow-slate-900/5 sm:p-8">
                            {children}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
