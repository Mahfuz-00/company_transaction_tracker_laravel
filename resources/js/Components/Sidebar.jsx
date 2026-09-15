import { Link, usePage } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';

export default function Sidebar({ user }) {
    // Get current route context from Inertia
    const { url } = usePage();

    // Helper function for active nav item styles
    const navLinkClasses = (routeName) => {
        const isActive = route().current(routeName);
        return `group relative flex items-center gap-3.5 px-3.5 py-3 rounded-xl font-semibold text-sm transition-all duration-200 ${
            isActive
                ? 'bg-slate-900 text-white shadow-md shadow-slate-900/10'
                : 'text-slate-600 hover:bg-slate-100/80 hover:text-slate-900'
        }`;
    };

    // Helper function for icon container colors
    const iconClasses = (routeName) => {
        const isActive = route().current(routeName);
        return `transition-colors duration-200 ${
            isActive ? 'text-white' : 'text-slate-400 group-hover:text-slate-700'
        }`;
    };

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

                {/* Main Navigation */}
                <nav aria-label="Main navigation" className="space-y-1.5">
                    {/* Dashboard */}
                    <Link 
                        href={route('dashboard')} 
                        className={navLinkClasses('dashboard')} 
                        aria-current={route().current('dashboard') ? 'page' : undefined}
                    >
                        <svg className={`h-5 w-5 ${iconClasses('dashboard')}`} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M3 13h8V3H3v10zM3 21h8v-6H3v6zM13 21h8V11h-8v10zM13 3v6h8V3h-8z" fill="currentColor"/>
                        </svg>
                        <span>Dashboard</span>
                    </Link>

                    {/* Analytics */}
                    <Link 
                        href={route('analytics')} 
                        className={navLinkClasses('analytics')} 
                        aria-current={route().current('analytics') ? 'page' : undefined}
                    >
                        <svg className={`h-5 w-5 ${iconClasses('analytics')}`} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <path d="M3 3v18h18" />
                            <path d="M18 17V9" />
                            <path d="M13 17V5" />
                            <path d="M8 17v-3" />
                        </svg>
                        <span>Analytics</span>
                    </Link>

                    {/* Add Transaction */}
                    <Link 
                        href={route('transactions.create')} 
                        className={navLinkClasses('transactions.create')} 
                        aria-current={route().current('transactions.create') ? 'page' : undefined}
                    >
                        <svg className={`h-5 w-5 ${iconClasses('transactions.create')}`} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M12 5v14M5 12h14" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" />
                        </svg>
                        <span>Add Transaction</span>
                    </Link>

                    {/* Settings */}
                    <Link 
                        href={route('settings')} 
                        className={navLinkClasses('settings')} 
                        aria-current={route().current('settings') ? 'page' : undefined}
                    >
                        <svg className={`h-5 w-5 ${iconClasses('settings')}`} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.38a2 2 0 0 0-.73-2.73l-.15-.1a2 2 0 0 1-1-1.72v-.51a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z" />
                            <circle cx="12" cy="12" r="3" />
                        </svg>
                        <span>Settings</span>
                    </Link>
                </nav>
            </div>

            {/* Profile & Footer Section */}
            <div className="space-y-3 pt-4 border-t border-slate-100">
                <div className="flex items-center gap-3 p-2 rounded-xl bg-slate-50/80 border border-slate-100">
                    <div className="h-10 w-10 rounded-lg bg-indigo-600 flex items-center justify-center text-xs font-bold text-white shadow-xs">
                        {user && user.name ? user.name.split(' ').map(n => n[0]).slice(0, 2).join('') : 'U'}
                    </div>
                    <div className="flex-1 min-w-0">
                        <div className="text-xs font-bold text-slate-800 truncate">{user?.name || 'User'}</div>
                        <div className="text-[11px] font-medium text-slate-400 truncate">{user?.email || 'mahfuz@example.com'}</div>
                    </div>
                </div>

                <Link 
                    href={route('logout')} 
                    method="post" 
                    as="button" 
                    className="w-full flex items-center justify-center gap-2 px-3.5 py-2.5 rounded-xl bg-rose-50 text-rose-600 hover:bg-rose-100 transition-colors duration-200 font-semibold text-xs"
                >
                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                    </svg>
                    <span>Log Out</span>
                </Link>
            </div>
        </aside>
    );
}