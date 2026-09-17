import Sidebar from '@/Components/Sidebar';
import ThemeProvider from '@/Components/ThemeProvider';
import NotificationBell from '@/Components/NotificationBell';
import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * Responsive application shell.
 *
 * Phone / tablet : the sidebar becomes an off-canvas drawer with a backdrop,
 *                  opened from a sticky top bar.
 * Desktop (lg+)  : the sidebar is docked and always visible.
 * Ultra-wide     : content is centred with a max width so tables stay readable
 *                  instead of stretching edge to edge.
 */
export default function AuthenticatedLayout({ header, children }) {
    const { auth, institution } = usePage().props;
    const user = auth?.user;

    const [drawerOpen, setDrawerOpen] = useState(false);

    // Close the drawer whenever the route changes (i.e. a nav link is tapped).
    useEffect(() => {
        setDrawerOpen(false);
    }, [children]);

    // Lock body scroll while the drawer is open on small screens.
    useEffect(() => {
        document.body.style.overflow = drawerOpen ? 'hidden' : '';
        return () => { document.body.style.overflow = ''; };
    }, [drawerOpen]);

    return (
        <ThemeProvider>
        <div className="min-h-screen bg-gray-50 text-gray-900">
            <div className="flex min-h-screen">
                {/* Docked sidebar (desktop) */}
                <div className="hidden lg:block lg:flex-shrink-0">
                    <Sidebar user={user} />
                </div>

                {/* Off-canvas drawer (mobile / tablet) */}
                {drawerOpen && (
                    <div
                        className="fixed inset-0 z-40 bg-slate-900/50 backdrop-blur-xs lg:hidden"
                        onClick={() => setDrawerOpen(false)}
                        aria-hidden="true"
                    />
                )}
                <div
                    className={`fixed inset-y-0 left-0 z-50 transform transition-transform duration-300 lg:hidden ${drawerOpen ? 'translate-x-0' : '-translate-x-full'
                        }`}
                >
                    <Sidebar user={user} onNavigate={() => setDrawerOpen(false)} />
                </div>

                {/* Main column */}
                <div className="flex min-w-0 flex-1 flex-col overflow-x-hidden">
                    {/* Mobile top bar with drawer trigger */}
                    <div className="sticky top-0 z-30 flex items-center gap-3 border-b border-slate-200 bg-white/90 px-4 py-3 backdrop-blur lg:hidden">
                        <button
                            type="button"
                            onClick={() => setDrawerOpen((open) => !open)}
                            aria-label="Open navigation"
                            aria-expanded={drawerOpen}
                            className="inline-flex h-10 w-10 items-center justify-center rounded-xl border-slate-200 text-slate-600 transition-colors hover:bg-slate-50"
                        >
                            <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                        </button>
                        <span className="truncate text-sm font-bold text-slate-800">
                            {institution?.name || 'Meal Manager'}
                        </span>
                    </div>

                    <div className="mx-auto w-full max-w-[1600px] px-4 py-5 sm:px-6 sm:py-6 lg:px-8">
                        {/* Header row: page title on the left, the notification
                            bell pinned right so it is reachable from every page
                            and every screen size. */}
                        <div className="mb-4 flex items-start justify-between gap-4">
                            <div className="min-w-0 flex-1">
                                {header && (
                                    <header>
                                        <div className="max-w-full">{header}</div>
                                    </header>
                                )}
                            </div>
                            <div className="flex-shrink-0">
                                <NotificationBell />
                            </div>
                        </div>

                        {/* Keyed by the current route so a page swap
                            animates in smoothly without the shell (sidebar,
                            header) ever remounting or flickering. */}
                        <main key={typeof window !== 'undefined' ? window.location.pathname : 'page'} className="animate-page-in">
                            {children}
                        </main>
                    </div>
                </div>
            </div>
        </div>
        </ThemeProvider>
    );
}
