import React, { useState, useEffect } from 'react';
import { Link, usePage } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import useCan from '@/Utils/can';

export default function Sidebar({ user }) {
    const page = usePage();
    const { can, hasRole, permissions, roles } = useCan();

    // ========== HEAVY DEBUG – keep this until fixed ==========
    useEffect(() => {
        console.group('%c[Sidebar Permission Debug]', 'color: #4f46e5; font-weight: bold;');
        console.log('1. Full page.props.auth →', page.props?.auth);
        console.log('2. auth.user →', page.props?.auth?.user);
        console.log('3. auth.roles (raw) →', page.props?.auth?.roles);
        console.log('4. auth.permissions (raw) →', page.props?.auth?.permissions);
        console.log('5. useCan() permissions →', permissions);
        console.log('6. useCan() roles →', roles);
        console.log('7. can("roles.view") →', can('roles.view'));
        console.log('8. hasRole("Super Admin") →', hasRole('Super Admin'));
        console.log('9. Array.isArray(permissions) →', Array.isArray(permissions));
        console.log('10. typeof permissions →', typeof permissions);
        console.groupEnd();
    }, [page.props, permissions, roles, can, hasRole]);
    // ========================================================

    const isSettingsActive = route().current('settings*');
    const [settingsOpen, setSettingsOpen] = useState(isSettingsActive);

    const navLinkClasses = (routeName) => {
        const isActive = route().current(routeName);
        return `group relative flex items-center gap-3.5 px-3.5 py-3 rounded-xl font-semibold text-sm transition-all duration-200 ${
            isActive
                ? 'bg-slate-900 text-white shadow-md shadow-slate-900/10'
                : 'text-slate-600 hover:bg-slate-100/80 hover:text-slate-900'
        }`;
    };

    const subNavLinkClasses = (routeName) => {
        const isActive = route().current(routeName);
        return `flex items-center gap-3 px-3.5 py-2.5 rounded-lg text-xs font-semibold transition-all duration-200 ${
            isActive
                ? 'bg-indigo-50 text-indigo-600 font-bold'
                : 'text-slate-500 hover:bg-slate-100 hover:text-slate-900'
        }`;
    };

    const iconClasses = (routeName) => {
        const isActive = route().current(routeName);
        return `transition-colors duration-200 ${
            isActive ? 'text-white' : 'text-slate-400 group-hover:text-slate-700'
        }`;
    };

    // Temporary: show for everyone so we can confirm the link itself works
    // Change to real check later: can('roles.view') || hasRole('Super Admin')
    const canSeeRoleManager = true; // ← FORCE SHOW for debugging

    return (
        <aside className="w-72 flex-shrink-0 h-screen sticky top-0 left-0 flex flex-col justify-between px-4 py-6 bg-white border-r border-slate-200/80 shadow-xs z-30">
            <div className="space-y-6">
                {/* Brand Header */}
                <div className="flex items-center gap-3.5 px-2">
                    <div className="flex items-center justify-center">
                        <ApplicationLogo className="h-10 w-10 object-contain" />
                    </div>
                    <div>
                        <h1 className="text-base font-bold text-slate-900 leading-tight">Transaction Tracker</h1>
                        <p className="text-xs font-medium text-slate-400">Manage your finances</p>
                    </div>
                </div>

                <hr className="border-slate-100" />

                <nav aria-label="Main navigation" className="space-y-1.5">
                    <Link href={route('dashboard')} className={navLinkClasses('dashboard')}>
                        <svg className={`h-5 w-5 ${iconClasses('dashboard')}`} viewBox="0 0 24 24" fill="none">
                            <path d="M3 13h8V3H3v10zM3 21h8v-6H3v6zM13 21h8V11h-8v10zM13 3v6h8V3h-8z" fill="currentColor" />
                        </svg>
                        <span>Dashboard</span>
                    </Link>

                    <Link href={route('analytics')} className={navLinkClasses('analytics')}>
                        <svg className={`h-5 w-5 ${iconClasses('analytics')}`} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                            <path d="M3 3v18h18" />
                            <path d="M18 17V9" />
                            <path d="M13 17V5" />
                            <path d="M8 17v-3" />
                        </svg>
                        <span>Analytics</span>
                    </Link>

                    <Link href={route('transactions.create')} className={navLinkClasses('transactions.create')}>
                        <svg className={`h-5 w-5 ${iconClasses('transactions.create')}`} viewBox="0 0 24 24" fill="none">
                            <path d="M12 5v14M5 12h14" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" />
                        </svg>
                        <span>Add Transaction</span>
                    </Link>

                    {/* Settings */}
                    <div>
                        <button
                            type="button"
                            onClick={() => setSettingsOpen(!settingsOpen)}
                            className={`w-full group relative flex items-center justify-between px-3.5 py-3 rounded-xl font-semibold text-sm transition-all duration-200 ${
                                isSettingsActive
                                    ? 'bg-slate-900 text-white shadow-md shadow-slate-900/10'
                                    : 'text-slate-600 hover:bg-slate-100/80 hover:text-slate-900'
                            }`}
                        >
                            <div className="flex items-center gap-3.5">
                                <svg
                                    className={`h-5 w-5 ${isSettingsActive ? 'text-white' : 'text-slate-400 group-hover:text-slate-700'}`}
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth="2"
                                >
                                    <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.38a2 2 0 0 0-.73-2.73l-.15-.1a2 2 0 0 1-1-1.72v-.51a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z" />
                                    <circle cx="12" cy="12" r="3" />
                                </svg>
                                <span>Settings</span>
                            </div>
                            <svg className={`w-4 h-4 transition-transform duration-200 ${settingsOpen ? 'rotate-180' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>

                        {settingsOpen && (
                            <div className="ml-4 pl-3 mt-1.5 border-l-2 border-slate-100 space-y-1">
                                <Link href={route('settings.currency')} className={subNavLinkClasses('settings.currency')}>
                                    <span>Currency Manager</span>
                                </Link>

                                {/* Role Manager – currently FORCED visible for debug */}
                                {canSeeRoleManager && (
                                    <Link href={route('settings.roles.index')} className={subNavLinkClasses('settings.roles.index')}>
                                        <span>Role Manager</span>
                                    </Link>
                                )}
                            </div>
                        )}
                    </div>
                </nav>
            </div>

            <div className="space-y-3 pt-4 border-t border-slate-100">
                <div className="flex items-center gap-3 p-2 rounded-xl bg-slate-50/80 border border-slate-100">
                    <div className="h-10 w-10 rounded-lg bg-indigo-600 flex items-center justify-center text-xs font-bold text-white">
                        {user?.name ? user.name.split(' ').map(n => n[0]).slice(0, 2).join('') : 'U'}
                    </div>
                    <div className="flex-1 min-w-0">
                        <div className="text-xs font-bold text-slate-800 truncate">{user?.name || 'User'}</div>
                        <div className="text-[11px] font-medium text-slate-400 truncate">{user?.email}</div>
                    </div>
                </div>

                <Link href={route('logout')} method="post" as="button" className="w-full flex items-center justify-center gap-2 px-3.5 py-2.5 rounded-xl bg-rose-50 text-rose-600 hover:bg-rose-100 font-semibold text-xs">
                    <span>Log Out</span>
                </Link>
            </div>
        </aside>
    );
}