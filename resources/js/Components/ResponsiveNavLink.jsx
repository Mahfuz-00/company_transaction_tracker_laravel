import { Link } from '@inertiajs/react';

/**
 * ResponsiveNavLink — the full-width navigation link used inside the mobile
 * hamburger menu (as opposed to the compact NavLink used on the desktop bar).
 *
 * Styled as a left-accented row that fills the drawer's width. It also wraps
 * Inertia's `<Link>`, so `href={route('name')}` performs a client-side visit.
 *
 * Props:
 *   - active?: boolean    Draws the highlight border/background. Set by the
 *                         caller, usually via `route().current('name')`.
 *   - className?: string  Extra classes, appended last.
 *   - children            Link label.
 *   - ...props            Spread onto `<Link>` (href, method, …).
 */
export default function ResponsiveNavLink({
    active = false,
    className = '',
    children,
    ...props
}) {
    // The active/inactive states differ only by class strings; the base `flex
    // w-full` classes make the row span the whole drawer width.
    return (
        <Link
            {...props}
            className={`flex w-full items-start border-l-4 py-2 pe-4 ps-3 ${
                active
                    ? 'border-white/20 bg-white/6 text-white'
                    : 'border-transparent text-white/80 hover:bg-white/6 hover:text-white'
            } text-base font-medium transition duration-150 ease-in-out focus:outline-none ${className}`}
        >
            {children}
        </Link>
    );
}
