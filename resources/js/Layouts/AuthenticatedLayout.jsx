import Sidebar from '@/Components/Sidebar';
import SupportAssistant from '@/Components/Assistant/SupportAssistant';
import ThemeProvider from '@/Components/ThemeProvider';
import TopBar from '@/Components/TopBar';
import { Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * Responsive application shell.
 *
 * Phone / tablet : the sidebar becomes an off-canvas drawer with a backdrop,
 *                  opened from a sticky top bar.
 * Desktop (lg+)  : the sidebar is docked and always visible.
 * Ultra-wide / TV: the content column widens at 2xl and 3xl so a 1920px+ display
 *                  is actually used, while still being capped so tables do not
 *                  stretch to unreadable line lengths.
 *
 * The shell is wrapped in ThemeProvider, so flipping the theme (or the global
 * ThemeToggle beside the bell) recolours the shell and every page inside it in
 * one paint.
 *
 * LAYOUT CONTRACT (the thing this shell exists to guarantee)
 *   The structure is strictly `[ LEFT: Sidebar ] [ RIGHT: Body ]`.
 *
 *   The sidebar is its own full-height scroll container (h-screen, sticky left).
 *   The body column sits beside it and owns the fixed top bar, which is
 *   `sticky top-0` WITHIN THAT COLUMN — so the bar spans only the body's width
 *   and can never overlap, stretch over, or sit on top of the sidebar.
 *
 *   Because the bar is sticky (in normal flow) rather than `fixed`, no spacer is
 *   required to stop content hiding beneath it: a previous version used a
 *   `fixed inset-x-0` bar plus an `h-14` spacer, and the fixed element spanned
 *   the whole viewport, painting across the sidebar's brand header.
 */
export default function AuthenticatedLayout({ header, children }) {
    const { auth, institution, tenant } = usePage().props;
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
        {/* The shell itself reads the theme tokens, so flipping dark mode recolours
            the page background and default text instantly. */}
        <div
            className="min-h-screen"
            style={{
                backgroundColor: 'var(--bg-color)',
                color: 'var(--text-primary)',
                /*
                 * THE SINGLE SOURCE OF TRUTH FOR THE RAIL WIDTH.
                 *
                 * Both the docked <Sidebar> and the fixed <TopBar> read this, so
                 * the bar's left offset can never drift from where the sidebar
                 * actually ends. Change this one value and both follow.
                 *
                 * 18rem = 288px, matching the sidebar's `w-72`.
                 */
                '--sidebar-width': '18rem',
            }}
        >
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

                {/* Main column — owns the top bar and the page content. */}
                <div className="flex h-screen min-w-0 flex-1 flex-col overflow-hidden">
                    {/* FIXED TOP BAR — `position: fixed`, but offset past the docked
                        sidebar via `--sidebar-width`, so it pins to the viewport
                        without ever painting over the rail.
                        Left: the Section > Module > Sub-Module > Title hierarchy.
                        Right: theme, notifications, profile menu. */}
                    <TopBar onMenuClick={() => setDrawerOpen((open) => !open)} />

                    {/* SPACER — REQUIRED BY `fixed`.

                        A fixed element is removed from normal flow, so it occupies
                        no space and the content would render UNDERNEATH it. This
                        spacer restores exactly the bar's height (h-14 = its
                        py-2.5 + content), so nothing is ever hidden beneath the
                        bar. It is `lg:` only because on mobile the rail is a drawer
                        and the bar still overlays the top of the column. */}
                    <div className="h-14 flex-shrink-0" aria-hidden="true" />

                    {/* The ONLY scroll container for page content. The sidebar
                        scrolls independently, so the two never fight for scroll
                        position. */}
                    <div className="min-h-0 flex-1 overflow-y-auto">
                        {/* Fluid content column: capped for readability, widening on
                            large monitors and TV-sized displays (2xl / 3xl). */}
                        <div className="mx-auto w-full max-w-[1600px] px-4 py-5 sm:px-6 sm:py-6 lg:px-8 2xl:max-w-[1760px] 3xl:max-w-[1920px]">
                        {/* Switched-view banner: only shown when a Software Super
                            Admin is inside another institution's workspace, so
                            they can always see WHICH tenant they are in and get
                            back to the global platform view in one click. */}
                        {tenant?.switched && (
                            <div className="mb-4 flex-col gap-2 rounded-xl border-amber-200 bg-amber-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                <div className="flex items-center gap-2 text-sm text-amber-800">
                                    <svg className="h-4 w-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                    <span>
                                        Viewing <strong className="font-semibold">{institution?.name}</strong> as a switched workspace.
                                    </span>
                                </div>
                                <Link
                                    href={route('settings.institutions.exit')}
                                    method="post"
                                    as="button"
                                    className="inline-flex items-center gap-1.5 self-start rounded-lg bg-amber-600 px-3.5 py-1.5 text-xs font-bold text-white transition-colors hover:bg-amber-700 sm:self-auto"
                                >
                                    Exit to platform view
                                </Link>
                            </div>
                        )}

                        {/* The page's ACTION BAR.

                            The page TITLE now lives in the fixed top bar, so pages
                            should no longer repeat it here. A page may still pass a
                            `header` for its descriptive line and action buttons (e.g.
                            "New Member") - those are content, not navigation, and
                            belong with the content. */}
                        {header && (
                            <header className="mb-4">
                                <div className="max-w-full">{header}</div>
                            </header>
                        )}

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

            {/*
              * THE SUPPORT ASSISTANT — DASHBOARD SURFACE.
              *
              * Mounted in the SHELL (not on individual pages) so it is available on
              * every screen a signed-in user reaches, and so the launcher keeps its
              * position while pages swap underneath it. A question usually arises
              * while looking at the thing that confused you, so the panel is
              * deliberately an overlay: the user never leaves the page they were
              * asking about.
              *
              * It renders itself as `fixed` at the bottom-right and reads the shared
              * `auth` prop to decide whether to load a persisted transcript.
              */}
            <SupportAssistant surface="dashboard" />
        </div>
        </ThemeProvider>
    );
}
