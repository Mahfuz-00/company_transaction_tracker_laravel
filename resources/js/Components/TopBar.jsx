import React, { useEffect, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import NotificationBell from '@/Components/NotificationBell';
import ProfileDropdown from '@/Components/ProfileDropdown';
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
 * "FIXED" means it stays pinned to the top of the viewport while the page
 * content scrolls beneath it: `sticky top-0` on the bar itself, with a high
 * z-index so dropdowns and the mobile drawer pass underneath it correctly.
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
            // PERMANENTLY FIXED: `fixed` + `top-0` + `left-0` pins the bar to the
            // viewport for EVERY dashboard, so it can never scroll out of view.
            // The sidebar offsets it on desktop (`lg:left-64`), matching the docked
            // sidebar's width, so the two never overlap.
            className="fixed inset-x-0 top-0 z-40 border-b backdrop-blur-md"
            style={{ backgroundColor: 'var(--surface)', borderColor: 'var(--border-color)' }}
        >
            <div className="mx-auto flex w-full max-w-[1600px] items-center gap-3 px-4 py-2.5 sm:px-6 lg:px-8 2xl:max-w-[1760px] 3xl:max-w-[1920px]">
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

                {/* RIGHT: theme, notifications, profile */}
                <div className="flex flex-shrink-0 items-center gap-1.5 sm:gap-2">
                    <ThemeToggle />
                    <NotificationBell />
                    <ProfileDropdown />
                </div>
            </div>
        </div>
    );
}