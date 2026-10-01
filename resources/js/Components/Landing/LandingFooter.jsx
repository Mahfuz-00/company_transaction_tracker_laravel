import React from 'react';
import { Link } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import usePlatformBranding from '@/Utils/usePlatformBranding';

/**
 * LandingFooter — the public landing page's footer.
 *
 * Shows the platform brand (read from `usePlatformBranding`, not props, so it
 * stays in sync with the SSA's branding), a copyright year computed at render
 * time, and the two account entry points (Log in / Register) as Inertia `<Link>`s
 * that perform client-side navigation.
 */
export default function LandingFooter() {
    const { name } = usePlatformBranding();

    return (
        <footer className="mt-20 border-t border-slate-200 bg-slate-900 text-slate-300 pt-16 pb-12" data-testid="onboarding-footer">
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div className="grid grid-cols-1 md:grid-cols-4 gap-8 pb-12 border-b border-slate-800">
                    <div className="space-y-4">
                        <div className="flex items-center gap-3">
                            <ApplicationLogo className="h-8 w-8 rounded-lg object-contain ring-1 ring-slate-700 bg-white" />
                            <span className="text-base font-bold text-white">{name}</span>
                        </div>
                        <p className="text-xs text-slate-400 leading-relaxed">
                            Complete multi-institution mess management, AI forecasting, automated per-meal calculations, and ledger transparency.
                        </p>
                    </div>

                    <div>
                        <h4 className="text-xs font-bold uppercase tracking-wider text-white mb-3">Product</h4>
                        <ul className="space-y-2 text-xs">
                            <li><a href="#features" className="hover:text-white transition-colors">Features</a></li>
                            <li><a href="#pricing" className="hover:text-white transition-colors">Pricing &amp; Plans</a></li>
                            <li><a href="#faq" className="hover:text-white transition-colors">FAQ</a></li>
                            <li><Link href={route('onboarding.institution.register')} className="hover:text-white transition-colors">Register Institution</Link></li>
                        </ul>
                    </div>

                    <div>
                        <h4 className="text-xs font-bold uppercase tracking-wider text-white mb-3">Access</h4>
                        <ul className="space-y-2 text-xs">
                            <li><Link href={route('login')} className="hover:text-white transition-colors">Member &amp; Admin Login</Link></li>
                            <li><Link href={route('register')} className="hover:text-white transition-colors">Join With Invite Code</Link></li>
                            <li><Link href={route('password.request')} className="hover:text-white transition-colors">Forgot Password</Link></li>
                        </ul>
                    </div>

                    <div>
                        <h4 className="text-xs font-bold uppercase tracking-wider text-white mb-3">Support &amp; Contact</h4>
                        <p className="text-xs text-slate-400 mb-2">Need help or enterprise onboarding assistance?</p>
                        <p className="text-xs text-indigo-400 font-semibold">support@nomnomytics.app</p>
                        <p className="text-[11px] text-slate-500 mt-2">Available 24/7 with Autonomous Vector AI assistance.</p>
                    </div>
                </div>

                <div className="pt-8 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-500">
                    <p>&copy; {new Date().getFullYear()} {name}. All rights reserved.</p>
                    <div className="flex items-center gap-6">
                        <a href="#privacy" className="hover:text-slate-400">Privacy Policy</a>
                        <a href="#terms" className="hover:text-slate-400">Terms of Service</a>
                        <a href="#security" className="hover:text-slate-400">Security &amp; Audit Trail</a>
                    </div>
                </div>
            </div>
        </footer>
    );
}
