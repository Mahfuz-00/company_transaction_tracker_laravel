import React, { useEffect, useMemo, useState } from 'react';
import { Link } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import Icon from '@/Components/Icon';
import useCan from '@/Utils/can';
import useTerminology from '@/Utils/useTerminology';
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

const topLevelClasses = (active) =>
    `group relative flex w-full items-center justify-between gap-3 px-3.5 py-3 rounded-xl font-semibold text-sm transition-all duration-200 ${active
        ? 'bg-slate-900 text-white shadow-md shadow-slate-900/10'
        : 'text-slate-600 hover:bg-slate-100/80 hover:text-slate-900'
    }`;

const childClasses = (active) =>
    `flex items-center gap-3 px-3.5 py-2.5 rounded-lg text-xs font-semibold transition-all duration-200 ${active
        ? 'bg-indigo-50 text-indigo-600 font-bold'
        : 'text-slate-500 hover:bg-slate-100 hover:text-slate-900'
    }`;

const iconToneClasses = (active, nested = false) => {
    if (active) {
        return nested ? 'text-indigo-500' : 'text-white';
    }
    return 'text-slate-400 group-hover:text-slate-700';
};

/* ------------------------------------------------------------------ *
 * Sidebar
 * ------------------------------------------------------------------ */

export default function Sidebar({ user }) {
    const { can, hasRole } = useCan();
    const { t, institution } = useTerminology();

    // Resolve a nav item's visible label: an explicit termKey follows the
    // institution type, otherwise the static label stands.
    const itemLabel = (item) => (item.termKey ? t(item.termKey, item.label) : item.label);

    // Everything permission-related happens here, once per render.
    const sections = useMemo(
        () => buildVisibleNav(NAV_SECTIONS, { can, hasRole }),
        [can, hasRole]
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
        <aside className="w-72 flex-shrink-0 h-screen sticky top-0 left-0 flex flex-col justify-between px-4 py-6 bg-white border-r border-slate-200/80 shadow-xs z-30 overflow-hidden">
            {/* Brand + navigation (Scrollable Area) */}
            <div className="flex-1 min-h-0 space-y-6 overflow-y-auto pr-1">
                <div className="flex items-center gap-3.5 px-2">
                    <ApplicationLogo className="h-10 w-10 object-contain" />
                    <div className="min-w-0">
                        <h1 className="text-base font-bold text-slate-900 leading-tight truncate">
                            {institution?.name || 'Meal Manager'}
                        </h1>
                        <p className="truncate text-xs font-medium text-slate-400">
                            {institution?.type_label || 'Shared meals, tracked'}
                        </p>
                    </div>
                </div>

                <hr className="border-slate-100" />

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

                                            {isOpen && (
                                                <div className="ml-4 pl-3 mt-1.5 border-l-2 border-slate-100 space-y-1">
                                                    {item.children.map((child) => {
                                                        const active = isRouteActive(
                                                            child.match,
                                                            child.route
                                                        );

                                                        return (
                                                            <Link
                                                                key={child.route}
                                                                href={route(child.route)}
                                                                className={childClasses(active)}
                                                            >
                                                                <span>{child.label}</span>
                                                            </Link>
                                                        );
                                                    })}
                                                </div>
                                            )}
                                        </div>
                                    );
                                }

                                /* ---- Plain link ---- */
                                const active = isRouteActive(item.match, item.route);

                                return (
                                    <Link
                                        key={item.route}
                                        href={route(item.route)}
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

            {/* Account footer (Pinned to Bottom) */}
            <div className="flex-shrink-0 space-y-3 pt-4 border-t border-slate-100 bg-white">
                <div className="flex items-center gap-3 p-2 rounded-xl bg-slate-50/80 border-slate-100">
                    <div className="h-10 w-10 flex-shrink-0 rounded-lg bg-indigo-600 flex items-center justify-center text-xs font-bold text-white">
                        {initials}
                    </div>
                    <div className="flex-1 min-w-0">
                        <div className="text-xs font-bold text-slate-800 truncate">
                            {user?.name || 'User'}
                        </div>
                        <div className="text-[11px] font-medium text-slate-400 truncate">
                            {user?.email}
                        </div>
                    </div>
                </div>

                <Link
                    href={route('logout')}
                    method="post"
                    as="button"
                    className="w-full flex items-center justify-center gap-2 px-3.5 py-2.5 rounded-xl bg-rose-50 text-rose-600 hover:bg-rose-100 font-semibold text-xs transition-colors"
                >
                    <span>Log Out</span>
                </Link>
            </div>
        </aside>
    );
}