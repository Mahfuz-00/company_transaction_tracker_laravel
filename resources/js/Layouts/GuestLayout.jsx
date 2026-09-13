import ApplicationLogo from '@/Components/ApplicationLogo';
import { Link } from '@inertiajs/react';

export default function GuestLayout({ children }) {
    return (
        <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-white via-slate-50 to-indigo-50 px-4">
            <div className="absolute inset-0 opacity-20 bg-[radial-gradient(ellipse_at_top_left,_var(--tw-gradient-stops))] from-white/5 to-transparent blur-lg"></div>
            <div className="relative z-10 w-full max-w-md">
                <div className="flex justify-center mb-6">
                    <Link href="/">
                        <ApplicationLogo className="h-28 w-auto object-contain" />
                    </Link>
                </div>

                <div className="bg-white/5 backdrop-blur-md border border-white/10 rounded-2xl px-8 py-8 shadow-xl">
                    {children}
                </div>
            </div>
        </div>
    );
}
