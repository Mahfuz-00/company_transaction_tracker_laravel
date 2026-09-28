import React, { useEffect, useRef, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';

/**
 * USER PROFILE DROPDOWN (right side of the Fixed Top Bar).
 *
 * Consolidates everything an account needs into one menu so the header stays
 * uncluttered:
 *
 *   - who you are (name / email / role badge),
 *   - Profile Manager,
 *   - Settings (theme customiser; plus User Manager for admins),
 *   - Replay the onboarding guide,
 *   - Log out.
 *
 * CLOSE BEHAVIOUR
 *   - click outside, Escape, or navigating away.
 *   The outside-click listener is attached on open and removed on close, so a
 *   closed menu adds no global listeners.
 *
 * ACCESSIBILITY
 *   - The trigger is a real button with `aria-haspopup` / `aria-expanded`.
 *   - Menu items are links/buttons (keyboard reachable, Tab-navigable).
 *   - `role="menu"` + `role="menuitem"` describe the structure to screen readers.
 */
export default function ProfileDropdown() {
    const { auth, viewingAs } = usePage().props;
    const user = auth?.user;
    const roles = auth?.roles || [];

    const [open, setOpen] = useState(false);
    const containerRef = useRef(null);

    // Close on outside click / Escape while open.
    useEffect(() => {
        if (!open) return undefined;

        const onPointerDown = (event) => {
            if (containerRef.current && !containerRef.current.contains(event.target)) {
                setOpen(false);
            }
        };

        const onKeyDown = (event) => {
            if (event.key === 'Escape') setOpen(false);
        };

        document.addEventListener('mousedown', onPointerDown);
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('mousedown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [open]);

    // Close after any Inertia navigation completes.
    useEffect(() => {
        const off = router.on('navigate', () => setOpen(false));
        return () => {
            // Inertia's router.on returns an unsubscribe function.
            if (typeof off === 'function') off();
        };
    }, []);

    if (!user) return null;

    const isSuperAdmin = Boolean(user.is_super_admin);
    const isAdmin = isSuperAdmin || roles.includes('Institution Admin');
    const primaryRole = isSuperAdmin ? 'Software Super Admin' : roles[0] || 'Member';

    const initials = (user.name || 'U')
        .trim()
        .split(/\s+/)
        .map((part) => part[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();

    const logout = () => {
        setOpen(false);
        router.post(route('logout'));
    };

    return (
        <div className="relative" ref={containerRef}>
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                data-testid="profile-dropdown-trigger"
                aria-haspopup="menu"
                aria-expanded={open}
                aria-label="Open user menu"
                className="flex items-center gap-2 rounded-xl border border-transparent p-1 pr-2 transition-colors hover:border-slate-200 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
            >
                {user.avatar_url ? (
                    <img
                        src={user.avatar_url}
                        alt={user.name}
                        className="h-8 w-8 rounded-lg object-cover"
                    />
                ) : (
                    <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-[var(--accent)] text-xs font-bold text-white">
                        {initials}
                    </span>
                )}

                <span className="hidden max-w-[10rem] flex-col items-start leading-tight sm:flex">
                    <span className="truncate text-xs font-bold text-slate-800">{user.name}</span>
                    <span className="truncate text-[10px] font-medium text-slate-400">{primaryRole}</span>
                </span>

                <svg
                    className={`h-3.5 w-3.5 flex-shrink-0 text-slate-400 transition-transform ${open ? 'rotate-180' : ''}`}
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                >
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 9l-7 7-7-7" />
                </svg>
            </button>

            {open && (
                <div
                    role="menu"
                    data-testid="profile-dropdown-menu"
                    className="absolute right-0 z-50 mt-2 w-64 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl animate-rise"
                >
                    {/* Identity header */}
                    <div className="border-b border-slate-100 bg-slate-50/70 px-4 py-3">
                        <p className="truncate text-sm font-bold text-slate-900">{user.name}</p>
                        <p className="truncate text-xs text-slate-500">{user.email}</p>
                        <div className="mt-2 flex flex-wrap items-center gap-1.5">
                            <span className="inline-flex items-center rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[10px] font-semibold text-slate-600">
                                {primaryRole}
                            </span>
                            {viewingAs?.is_impersonating && (
                                <span className="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-700">
                                    Managing {viewingAs.target_institution_name || 'a workspace'}
                                </span>
                            )}
                        </div>
                    </div>

                    <div className="py-1">
                        <Link
                            href={route('profile.edit')}
                            role="menuitem"
                            data-testid="profile-menu-profile"
                            className="flex items-center gap-2.5 px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50"
                        >
                            <svg className="h-4 w-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                            Profile Manager
                        </Link>

                        <Link
                            href={route('settings.theme.edit')}
                            role="menuitem"
                            data-testid="profile-menu-settings"
                            className="flex items-center gap-2.5 px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50"
                        >
                            <svg className="h-4 w-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                            Theme &amp; Settings
                        </Link>

                        {isAdmin && (
                            <Link
                                href={route('settings.users.index')}
                                role="menuitem"
                                data-testid="profile-menu-users"
                                className="flex items-center gap-2.5 px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50"
                            >
                                <svg className="h-4 w-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                                </svg>
                                User Manager
                            </Link>
                        )}

                        <button
                            type="button"
                            role="menuitem"
                            data-testid="profile-menu-replay-tour"
                            onClick={() => {
                                setOpen(false);
                                router.post(route('onboarding.replay'));
                            }}
                            className="flex w-full items-center gap-2.5 px-4 py-2 text-left text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50"
                        >
                            <svg className="h-4 w-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            Replay the tour
                        </button>
                    </div>

                    <div className="border-t border-slate-100 py-1">
                        <button
                            type="button"
                            role="menuitem"
                            onClick={logout}
                            data-testid="profile-menu-logout"
                            className="flex w-full items-center gap-2.5 px-4 py-2 text-left text-sm font-medium text-rose-600 transition-colors hover:bg-rose-50"
                        >
                            <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                            </svg>
                            Log out
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}