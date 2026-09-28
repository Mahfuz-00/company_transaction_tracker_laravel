import React, { useEffect, useMemo, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import Icon from '@/Components/Icon';
import ReportBug from '@/Components/ReportBug';
import useCan from '@/Utils/can';
import useTerminology from '@/Utils/useTerminology';
import usePlatformBranding from '@/Utils/usePlatformBranding';
import { NAV_SECTIONS, buildVisibleNav } from '@/Utils/navItems';

/* ------------------------------------------------------------------ *
 * Styling helpers — pure functions, no permission logic
 * ------------------------------------------------------------------ */

const isRouteActive = (match, routeName) => {
    const pattern = match || routeName;
    if (!pattern) return false;

    // route().current() accepts a wildcard, so exact names work too.
    return Boolean(route().current(pattern));
};

/**
 * Colours come from CSS variables written by the theme layer (see app.jsx),
 * so changing a workspace accent repaints the sidebar with no code change.
 */
const topLevelClasses = (active) =>
    `group relative flex w-full items-center justify-between gap-3 px-3.5 py-2 rounded-xl font-semibold text-sm transition-colors duration-150 ${active
        ? 'bg-[var(--accent)] text-white shadow-sm'
        : 'text-slate-600 hover:bg-slate-100/80 hover:text-slate-900'
    }`;

const childClasses = (active) =>
    `flex items-center gap-3 px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-colors duration-150 ${active
        ? 'bg-[var(--accent-soft)] text-[var(--accent)] font-bold'
        : 'text-slate-500 hover:bg-slate-100 hover:text-slate-900'
    }`;

const iconToneClasses = (active, nested = false) => {
    if (active) {
        return nested ? 'text-[var(--accent)]' : 'text-white';
    }
    return 'text-slate-400 group-hover:text-slate-700';
};

/* ------------------------------------------------------------------ *
 * Sidebar
 * ------------------------------------------------------------------ */

export default function Sidebar({ user, onNavigate }) {
    const { can, hasRole, isSuperAdmin } = useCan();
    const { t, institution } = useTerminology();
    const { controlCenter, adminSubtitle, logoUrl } = usePlatformBranding();
    const { auth, tenant } = usePage().props;

    /*
     * BRAND HEADER CONTEXT - the tenant-vs-global decision, in ONE place.
     *
     * The header must answer exactly one question: "which context am I in right
     * now?" Getting this wrong is the regression this block exists to prevent -
     * a previous iteration leaked a tenant institution's name/logo into the
     * Software Super Admin's GLOBAL view, implying the SSA belonged to whichever
     * workspace happened to be the default.
     *
     *   - SOFTWARE SUPER ADMIN, global view (not switched in):
     *       software name ("NomNomytics") + software subtitle + platform logo.
     *       No tenant name, no tenant logo - ever.
     *
     *   - SOFTWARE SUPER ADMIN, switched INTO a workspace:
     *       that institution's name + subtitle + logo. They ARE inside it, and it
     *       must stay obvious which workspace they are operating on.
     *
     *   - EVERYONE ELSE (Institution Admin / Meal Manager / Member):
     *       their own institution's name + subtitle + logo, falling back to the
     *       software logo when the institution has not uploaded one.
     *
     * `tenant.switched` is computed SERVER-SIDE (HandleInertiaRequests) as
     * "a session tenant is set AND it differs from the user's own institution",
     * so a tenant user is never mistakenly treated as a switched SSA.
     */
    const showPlatformBrand = isSuperAdmin && !tenant?.switched;

    /*
     * The resolved brand header. Deriving a single object (rather than repeating
     * ternaries in the JSX) keeps the name/subtitle/logo triple guaranteed
     * CONSISTENT: it is impossible for the header to show an institution's name
     * next to the software subtitle, or vice versa.
     */
    const brand = showPlatformBrand
        ? {
            name: controlCenter,
            subtitle: adminSubtitle,
            // The platform lockup. `logoUrl` may legitimately be null (no platform
            // logo uploaded), in which case the JSX below renders the built-in
            // ApplicationLogo mark - so the header is NEVER blank.
            logoUrl: logoUrl || null,
        }
        : {
            name: institution?.name || controlCenter,
            subtitle: institution?.subtitle || institution?.type_label || 'Shared meals, tracked',
            /*
             * LOGO FALLBACK RULE (the regression this guards).
             *
             * A tenant institution that has NOT uploaded a logo must fall back to
             * the SOFTWARE logo - not to nothing, and not to another tenant's
             * mark. Resolving the fallback HERE (rather than only in the JSX)
             * means `brand.logoUrl` is always the correct image for the context,
             * and the JSX needs no second guess about which logo to show.
             *
             * If neither exists, `logoUrl` stays null and the built-in
             * ApplicationLogo mark renders instead.
             */
            logoUrl: institution?.logo_url || logoUrl || null,
        };

    // True when a Software Super Admin is inside a switched tenant session:
    // that is the ONLY case in which the tenant Meal Management modules appear
    // for the SSA (see buildVisibleNav's tenantScoped handling).
    const switched = Boolean(tenant?.switched);

    // The freshest avatar lives on the shared auth prop, so a profile-picture
    // change reflects immediately without a full reload.
    const avatarUrl = auth?.user?.avatar_url || user?.avatar_url || null;

    // Resolve a nav item's visible label: an explicit termKey follows the
    // institution type, otherwise the static label stands.
    const itemLabel = (item) => (item.termKey ? t(item.termKey, item.label) : item.label);

    // Everything permission-related happens here, once per render.
    const sections = useMemo(
        () => buildVisibleNav(NAV_SECTIONS, { can, hasRole, switched }),
        [can, hasRole, switched]
    );

    // The bug-report modal's open state lives here, beside its trigger.
    const [bugOpen, setBugOpen] = useState(false);

    // Which collapsible groups are expanded. Default-open if the user is
    // currently inside that group, so a deep link keeps its parent visible.
    const [openGroups, setOpenGroups] = useState(() => {
        const initial = {};
        NAV_SECTIONS.forEach((section) => {
            (section.items || []).forEach((item) => {
                if (Array.isArray(item.children)) {
                    initial[item.label] = item.children.some((child) =>
                        isRouteActive(child.match, child.route)
                    );
                }
            });
        });
        return initial;
    });

    // If a group's child becomes active via navigation, make sure it is open.
    useEffect(() => {
        setOpenGroups((current) => {
            let changed = false;
            const next = { ...current };

            sections.forEach((section) => {
                section.items.forEach((item) => {
                    if (!Array.isArray(item.children)) return;

                    const childIsActive = item.children.some((child) =>
                        isRouteActive(child.match, child.route)
                    );

                    if (childIsActive && !next[item.label]) {
                        next[item.label] = true;
                        changed = true;
                    }
                });
            });

            return changed ? next : current;
        });
    }, [sections]);

    /**
     * Toggle a group: explicitly flips the boolean stored for this label, so
     * the group both opens (down) and closes (up) reliably.
     */
    const toggleGroup = (label) =>
        setOpenGroups((current) => ({ ...current, [label]: !current[label] }));

    const initials = user?.name
        ? user.name
            .split(' ')
            .map((part) => part[0])
            .slice(0, 2)
            .join('')
            .toUpperCase()
        : 'U';

    return (
        <aside
            /*
             * FULL-HEIGHT DOCKED SIDEBAR.
             *
             * `h-screen` + `sticky top-0` is deliberate: the sidebar is its own
             * scroll container that stays put while the BODY column scrolls
             * independently beside it. `h-screen` (not `h-full`) is required
             * because the flex parent is `min-h-screen` and can grow — `h-full`
             * would resolve against a growing container and stretch the rail.
             *
             * The top bar is NOT rendered here and does not overlap this element:
             * it lives inside the body column (see TopBar.jsx), so the brand
             * header below owns the full height of the rail, top to bottom.
             */
            className="sticky left-0 top-0 z-30 flex h-screen w-72 max-w-80 flex-shrink-0 flex-col justify-between overflow-hidden border-r border-slate-200/80 bg-white px-3 py-4 shadow-xs"
        >
            {/* ---- Compact sticky brand header ----
                 Context-aware: the platform lockup for an SSA in the global view,
                 the institution lockup everywhere else (see `brand` above). The
                 logo falls back to the shared ApplicationLogo whenever neither a
                 platform logo nor an institution logo exists, so the header is
                 never blank. */}
            <div
                className="sticky top-0 z-10 flex-shrink-0 border-b border-slate-100 bg-white px-2 pb-3 pt-2"
                data-testid="sidebar-brand"
                data-brand-context={showPlatformBrand ? 'platform' : 'institution'}
            >
                <div className="flex items-center gap-3 px-1">
                    {brand.logoUrl ? (
                        <img
                            src={brand.logoUrl}
                            alt={brand.name}
                            className="h-8 w-8 flex-shrink-0 rounded-lg object-contain"
                        />
                    ) : (
                        <span className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-slate-900 text-white">
                            {showPlatformBrand ? (
                                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
                                </svg>
                            ) : (
                                <ApplicationLogo className="h-5 w-5 object-contain" />
                            )}
                        </span>
                    )}
                    <div className="min-w-0">
                        <h1 className="truncate text-sm font-bold leading-tight text-slate-900">
                            {brand.name}
                        </h1>
                        <p className="truncate text-[11px] font-medium text-slate-400">
                            {brand.subtitle}
                        </p>
                    </div>
                </div>
            </div>

            {/* ---- Scrollable navigation ---- */}
            <div className="min-h-0 flex-1 overflow-y-auto px-2 py-3">
                <nav aria-label="Main navigation" className="space-y-4">
                    {sections.map((section, sectionIndex) => (
                        <div key={section.heading || sectionIndex} className="space-y-1">
                            {section.heading && (
                                <p className="px-3 pb-1 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                    {section.heading}
                                </p>
                            )}

                            {section.items.map((item) => {
                                /* ---- Collapsible group ---- */
                                if (Array.isArray(item.children)) {
                                    const groupActive = item.children.some((child) =>
                                        isRouteActive(child.match, child.route)
                                    );
                                    const isOpen = Boolean(openGroups[item.label]);

                                    return (
                                        <div key={item.label}>
                                            <button
                                                type="button"
                                                onClick={() => toggleGroup(item.label)}
                                                aria-expanded={isOpen}
                                                className={topLevelClasses(groupActive)}
                                            >
                                                <span className="flex items-center gap-3">
                                                    <Icon
                                                        name={item.icon}
                                                        className={`h-4 w-4 ${iconToneClasses(groupActive)}`}
                                                    />
                                                    <span>{itemLabel(item)}</span>
                                                </span>
                                                <Icon
                                                    name="chevronDown"
                                                    className={`h-3.5 w-3.5 transition-transform duration-200 ${isOpen ? 'rotate-180' : ''}`}
                                                />
                                            </button>

                                            <div
                                                className={`grid transition-all duration-200 ease-out ${isOpen ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'}`}
                                          >
                                            <div className="ml-4 mt-0.5 space-y-0.5 overflow-hidden border-l-2 border-slate-100 pl-3">
                                                {item.children.map((child) => {
                                                    const active = isRouteActive(
                                                        child.match,
                                                        child.route
                                                  );

                                                    return (
                                                        <Link
                                                            key={child.route}
                                                            href={route(child.route)}
                                                            onClick={onNavigate}
                                                            className={childClasses(active)}
                                                        >
                                                            <span>{child.label}</span>
                                                        </Link>
                                                    );
                                                })}
                                              </div>
                                            </div>
                                      </div>
                                );
                            }

                            /* ---- Plain link ---- */
                            const active = isRouteActive(item.match, item.route);

                            return (
                                <Link
                                    key={item.route}
                                    href={route(item.route)}
                                    onClick={onNavigate}
                                    className={topLevelClasses(active)}
                                    aria-current={active ? 'page' : undefined}
                                >
                                    <span className="flex items-center gap-3">
                                        <Icon
                                            name={item.icon}
                                            className={`h-4 w-4 ${iconToneClasses(active)}`}
                                        />
                                        <span>{itemLabel(item)}</span>
                                    </span>
                            </Link>
                        );
                    })}
                </div>
                ))}
              </nav>
          </div>

          {/* ---- Compact pinned profile footer ---- */}
          <div className="flex-shrink-0 border-t border-slate-100 bg-white px-2 py-2">
              {/*
               * REPORT BUG - every role EXCEPT the Software Super Admin.
               *
               * The SSA is the RECIPIENT of these reports, so offering them the
               * button would be circular (and the server refuses it outright).
               * For everyone else it sits here in the pinned footer, which is
               * always visible without competing with navigation for attention.
               */}
              {!isSuperAdmin && (
                  <button
                      type="button"
                      onClick={() => setBugOpen(true)}
                      data-testid="report-bug-trigger"
                      className="mb-1.5 flex w-full items-center gap-2 rounded-xl px-2.5 py-2 text-xs font-semibold text-slate-500 transition-colors hover:bg-rose-50 hover:text-rose-600"
                  >
                      <svg className="h-3.5 w-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8v4m0 4h.01M5.07 19h13.86a2 2 0 001.74-2.99l-6.93-12a2 2 0 00-3.48 0l-6.93 12A2 2 0 005.07 19z" />
                      </svg>
                      Report a bug
                  </button>
              )}

              <div className="flex items-center gap-1.5 rounded-xl bg-slate-50/80 p-1.5">
                  <Link
                      href={route('profile.edit')}
                      onClick={onNavigate}
                      className="flex min-w-0 flex-1 items-center gap-2"
                      title="Open profile"
                  >
                      {avatarUrl ? (
                          <img
                              src={avatarUrl}
                              alt={user?.name || 'Profile'}
                              className="h-7 w-7 flex-shrink-0 rounded-lg object-cover"
                          />
                      ) : (
                          <span className="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-lg bg-[var(--accent)] text-[10px] font-bold text-white">
                              {initials}
                          </span>
                      )}
                      <span className="min-w-0 flex-1">
                          <span className="block truncate text-xs font-bold text-slate-800 leading-tight">
                              {user?.name || 'User'}
                          </span>
                          <span className="block truncate text-[10px] font-medium text-slate-400">
                              {user?.designation || user?.email || ''}
                          </span>
                      </span>
                  </Link>

                  <Link
                      href={route('logout')}
                      method="post"
                      as="button"
                      aria-label="Log out"
                      title="Log out"
                      className="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-lg text-slate-400 transition-colors hover:bg-rose-50 hover:text-rose-600"
                  >
                      <svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                      </svg>
                </Link>
            </div>
                        </div>

                            {/* The bug-report modal itself. Rendered only for non-SSA users, and
                                only when opened, so the form state is always fresh. */}
                            {!isSuperAdmin && <ReportBug show={bugOpen} onClose={() => setBugOpen(false)} />}
                      </aside>
                  );
                }