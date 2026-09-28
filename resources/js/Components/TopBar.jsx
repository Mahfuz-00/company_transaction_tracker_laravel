import React, { useEffect, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import NotificationBell from '@/Components/NotificationBell';
import ProfileDropdown from '@/Components/ProfileDropdown';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import ThemeToggle from '@/Components/ThemeToggle';
import { resolvePageMeta } from '@/Utils/pageMeta';

/**
 * FIXED TOP BAR — the application's persistent header.
 *
 * LAYOUT
 *   Left  : breadcrumb trail (section › page) and the current page title.
 *   Right : theme toggle, notification bell, and the user profile dropdown
 *           (profile, settings, replay tour, log out).
 *
 * "FIXED" — AND WHAT THAT MEANS FOR THE SIDEBAR
 *   The bar stays pinned while the page content scrolls beneath it. It is
 *   achieved with `sticky top-0` WITHIN THE BODY COLUMN, not with
 *   `fixed inset-x-0`.
 *
 *   That distinction is the whole point. `fixed inset-x-0 top-0` pins the bar to
 *   the VIEWPORT and stretches it across the full screen width — including the
 *   area occupied by the docked sidebar — so it visually sat ON TOP OF the
 *   sidebar's brand header. The layout must be `[Left: Sidebar] [Right: Body]`,
 *   with the top bar belonging to the BODY ONLY.
 *
 *   Because this component renders inside the body column (see
 *   AuthenticatedLayout's main column), a plain `sticky top-0` naturally:
 *     - spans exactly the body column's width (never the sidebar's), and
 *     - sticks to the top of the scrolling column without any `left-*` offset
 *       math that would drift the moment the sidebar width changed.
 *
 * The bar is rendered for BOTH desktop and mobile. On small screens the
 * breadcrumb collapses to just the page title and the profile dropdown shrinks
 * to an avatar, so the header never wraps or overflows.
 *
 * PAGE TITLE SOURCE
 *   `Utils/pageMeta.js` maps the current pathname to a title + breadcrumb trail.
 *   A page may ALSO pass an explicit `headerTitle` prop down from the layout,
 *   which always wins - that lets a dashboard show e.g. "Welcome, Samira"
 *   while the breadcrumb still reads "My Account › Summary".
 *
 * @param {object}  props
 * @param {string}  [props.title]      - explicit title override
 * @param {function} [props.onMenuClick] - opens the mobile nav drawer
 */
export default function TopBar({ title: titleOverride = null, onMenuClick = null }) {
    const { institution, tenant } = usePage().props;
    const [pathname, setPathname] = useState(
        typeof window !== 'undefined' ? window.location.pathname : '/',
    );

    // Keep the breadcrumb in step with client-side (Inertia) navigation. Inertia
    // swaps the page without a full reload, so `window.location.pathname` is
    // accurate but nothing re-renders the bar on its own - we listen for
    // history changes (popstate + the pushState Inertia performs).
    useEffect(() => {
        if (typeof window === 'undefined') return undefined;

        const sync = () => setPathname(window.location.pathname);

        window.addEventListener('popstate', sync);

        // Inertia uses history.pushState; wrapping it lets us react to visits.
        const originalPush = window.history.pushState;
        window.history.pushState = function patched(...args) {
            const result = originalPush.apply(this, args);
            sync();
            return result;
        };

        return () => {
            window.removeEventListener('popstate', sync);
            window.history.pushState = originalPush;
        };
    }, []);

    const meta = resolvePageMeta(pathname);
    const title = titleOverride || meta.title;

    return (
        <div
            data-testid="fixed-top-bar"
            /*
             * FIXED — TO THE VIEWPORT, BUT CONFINED TO THE BODY COLUMN.
             *
             * Two requirements have to hold at once, and they pull in opposite
             * directions:
             *
             *   1. The bar must be `position: fixed` so it can NEVER scroll out of
             *      view, no matter how long the page is.
             *   2. It must sit ONLY over the body, never on top of the docked
             *      sidebar (`[Left: Sidebar] [Right: Body]`).
             *
             * A plain `fixed inset-x-0` satisfies (1) and BREAKS (2): it stretches
             * the full viewport width, so it paints across the sidebar's brand
             * header. That was the original defect.
             *
             * The fix is to keep `fixed` and constrain the LEFT edge to the
             * sidebar's width. `inset-inline-start` (logical, so it follows RTL)
             * offsets the bar past the docked rail on desktop.
             *
             * WHY A CSS VARIABLE FOR THE WIDTH
             *   `--sidebar-width` is published by AuthenticatedLayout from a single
             *   constant, so the rail and the bar can never disagree about where the
             *   sidebar ends. Hard-coding `left-72` (18rem) here would silently
             *   desync the moment the rail is resized.
             *
             * On small screens the rail is an off-canvas drawer (not docked), so the
             * offset is 0 and the bar spans the full width as it should.
             *
             * `z-30` keeps it above page content but BELOW the mobile drawer
             * (z-40 backdrop / z-50 panel), so the drawer correctly covers it.
             */
            className="fixed top-0 inset-x-0 z-30 border-b backdrop-blur-md lg:inset-x-auto lg:end-0"
            style={{
                backgroundColor: 'var(--surface)',
                borderColor: 'var(--border-color)',
                // Confine the bar to the body column on desktop (drawer on mobile).
                insetInlineStart: 'var(--sidebar-width, 0px)',
            }}
        >
            <div className="flex w-full items-center gap-3 px-4 py-2.5 sm:px-6 lg:px-8">
                {/* Mobile-only drawer trigger */}
                <button
                    type="button"
                    onClick={onMenuClick}
                    aria-label="Open navigation"
                    data-testid="topbar-menu-trigger"
                    className="inline-flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl border-slate-200 text-slate-600 transition-colors hover:bg-slate-50 lg:hidden"
                >
                    <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>

                {/* LEFT: breadcrumbs (Section > Module > Sub-Module > Title) */}
                <div className="min-w-0 flex-1">
                    <nav
                        aria-label="Breadcrumb"
                        data-testid="topbar-breadcrumbs"
                        className="hidden items-center gap-1.5 text-[11px] font-medium text-slate-400 sm:flex"
                    >
                        {meta.crumbs.map((crumb, index) => {
                            const isLast = index === meta.crumbs.length - 1;

                            return (
                                <React.Fragment key={`${crumb.label}-${index}`}>
                                    {index > 0 && (
                                        <svg className="h-3 w-3 flex-shrink-0 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 5l7 7-7 7" />
                                        </svg>
                                    )}
                                    {crumb.url && !isLast ? (
                                        <Link href={crumb.url} className="truncate transition-colors hover:text-slate-700">
                                            {crumb.label}
                                        </Link>
                                    ) : (
                                        <span
                                            className={isLast ? 'truncate font-semibold text-slate-600' : 'truncate'}
                                            aria-current={isLast ? 'page' : undefined}
                                        >
                                            {crumb.label}
                                        </span>
                                    )}
                                </React.Fragment>
                            );
                        })}
                    </nav>

                    <h1
                        data-testid="topbar-page-title"
                        className="truncate text-sm font-bold text-slate-900 sm:text-base"
                    >
                        {title}
                    </h1>
                </div>

                {/* Switched-tenant indicator, so an SSA always knows which workspace
                    they are looking at from the header itself. */}
                {tenant?.switched && (
                    <span className="hidden items-center gap-1.5 rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-[10px] font-bold text-amber-700 md:inline-flex">
                        <span className="h-1.5 w-1.5 rounded-full bg-amber-500" />
                        {institution?.name || 'Switched workspace'}
                    </span>
                )}

                {/* RIGHT: language, theme, notifications, profile */}
                <div className="flex flex-shrink-0 items-center gap-1.5 sm:gap-2">
                    {/* Language sits FIRST on the right rail: it is the setting a
                        user is most likely to change immediately and then never
                        touch again, so it should be findable without hunting. */}
                    <LanguageSwitcher compact />
                    <ThemeToggle />
                    <NotificationBell />
                    <ProfileDropdown />
                </div>
            </div>
        </div>
    );
}