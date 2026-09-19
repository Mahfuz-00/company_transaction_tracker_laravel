import React, { useEffect, useMemo, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import Icon from '@/Components/Icon';
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
    `group relative flex w-full items-center justify-between gap-3 px-3.5 py-2.5 rounded-xl font-semibold text-sm transition-colors duration-150 ${active
        ? 'bg-[var(--accent)] text-white shadow-sm'
        : 'text-slate-600 hover:bg-slate-100/80 hover:text-slate-900'
    }`;

const childClasses = (active) =>
    `flex items-center gap-3 px-3.5 py-2 rounded-lg text-xs font-semibold transition-colors duration-150 ${active
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
     * BRAND HEADER CONTEXT.
     *
     * A Software Super Admin operates GLOBALLY, not inside one workspace, so the
     * sidebar must NOT show a tenant institution name (which wrongly implied the
     * SSA "belonged" to whichever workspace happened to be active).
     *
     *   - SSA, not switched in : platform branding ("SaaS Control Center").
     *   - SSA, switched into a tenant : that tenant's name (they ARE inside it),
     *     so it stays clear which workspace they are operating in.
     *   - Everyone else : their own institution.
     */
    const showPlatformBrand = isSuperAdmin && !tenant?.switched;

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
        <aside className="flex h-screen w-72 max-w-80 flex-shrink-0 h-screen sticky top-0 left-0 flex flex-col justify-between px-4 py-6 bg-white border-r border-slate-200/80 shadow-xs z-30 overflow-hidden">
            {/* ---- Sticky brand header ---- */}
            <div className="sticky top-0 z-10 flex-shrink-0 border-b border-slate-100 bg-white px-4 pb-4 pt-6">
                <div className="flex items-center gap-3.5 px-2">
                    {/* The SSA sees the PLATFORM logo (or a control-centre glyph);
                        everyone else sees their institution's own logo. */}
                    {showPlatformBrand && logoUrl ? (
                        <img src={logoUrl} alt={controlCenter} className="h-10 w-10 flex-shrink-0 rounded-lg object-contain" />
                    ) : showPlatformBrand || !institution?.logo_url ? (
                        <span className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-slate-900 text-white">
                            {showPlatformBrand ? (
                                <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
                                </svg>
                            ) : (
                                <ApplicationLogo className="h-6 w-6 object-contain" />
                            )}
                        </span>
                    ) : (
                        <img
                            src={institution.logo_url}
                            alt={institution.name}
                            className="h-10 w-10 flex-shrink-0 rounded-lg object-contain"
                        />
                    )}
                    <div className="min-w-0">
                        <h1 className="truncate text-base font-bold leading-tight text-slate-900">
                            {showPlatformBrand ? controlCenter : (institution?.name || controlCenter)}
                        </h1>
                        <p className="truncate text-xs font-medium text-slate-400">
                            {showPlatformBrand
                                ? adminSubtitle
                                : (institution?.subtitle || institution?.type_label || 'Shared meals, tracked')}
                        </p>
                    </div>
                </div>
            </div>

            {/* ---- Scrollable navigation ---- */}
            <div className="min-h-0 flex-1 overflow-y-auto px-4 py-4">
                <nav aria-label="Main navigation" className="space-y-5">
                    {sections.map((section, sectionIndex) => (
                        <div key={section.heading || sectionIndex} className="space-y-1.5">
                            {section.heading && (
                                <p className="px-3.5 pb-1 text-[10px] font-bold uppercase tracking-wider text-slate-400">
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
                                                <span className="flex items-center gap-3.5">
                                                    <Icon
                                                        name={item.icon}
                                                        className={`h-5 w-5 ${iconToneClasses(groupActive)}`}
                                                    />
                                                    <span>{itemLabel(item)}</span>
                                                </span>
                                                <Icon
                                                    name="chevronDown"
                                                    className={`h-4 w-4 transition-transform duration-200 ${isOpen ? 'rotate-180' : ''
                                                        }`}
                                                />
                                            </button>

                                            {/* Animates open/close with a
                                                grid-rows transition, so the
                                                toggle is smooth both ways. */}
                                            <div
                                                className={`grid transition-all duration-200 ease-out ${isOpen
                                                    ? 'grid-rows-[1fr] opacity-100'
                                                    : 'grid-rows-[0fr] opacity-0'
                                                    }`}
                                            >
                                                <div className="ml-4 mt-1 space-y-1 overflow-hidden border-l-2 border-slate-100 pl-3">
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
                                        <span className="flex items-center gap-3.5">
                                            <Icon
                                                name={item.icon}
                                                className={`h-5 w-5 ${iconToneClasses(active)}`}
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

            {/* ---- Compact pinned profile footer ----
                Profile info on the left, a logout icon button to its side, so
                the whole block stays one row tall instead of stacking. */}
            <div className="flex-shrink-0 border-t border-slate-100 bg-white p-3">
                <div className="flex items-center gap-2 rounded-xl bg-slate-50/80 p-2">
                    <Link
                        href={route('profile.edit')}
                        onClick={onNavigate}
                        className="flex min-w-0 flex-1 items-center gap-2.5"
                        title="Open profile"
                    >
                        {avatarUrl ? (
                            <img
                                src={avatarUrl}
                                alt={user?.name || 'Profile'}
                                className="h-8 w-8 flex-shrink-0 rounded-lg object-cover"
                            />
                        ) : (
                            <span className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-[var(--accent)] text-[11px] font-bold text-white">
                                {initials}
                            </span>
                        )}
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-xs font-bold text-slate-800">
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
                        className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg text-slate-400 transition-colors hover:bg-rose-50 hover:text-rose-600"
                    >
                        <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                        </svg>
                    </Link>
                </div>
            </div>
        </aside>
    );
}