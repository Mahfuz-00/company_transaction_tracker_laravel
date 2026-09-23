import { Link } from '@inertiajs/react';

/**
 * NavLink — a top-bar navigation link that highlights when active.
 *
 * Wraps Inertia's `<Link>`: give it an `href` (usually `route('name')` from
 * Ziggy) and a click becomes a client-side visit that swaps the page component
 * without a full browser reload.
 *
 * Props:
 *   - active?: boolean    Highlights the link. The CALLER computes this, typically
 *                         with `route().current('name')`, since only the parent
 *                         knows which route each link points at.
 *   - className?: string  Extra classes, appended last.
 *   - children            The link text.
 *   - ...props            Spread onto `<Link>` (href, method, etc.).
 */
export default function NavLink({
    active = false,
    className = '',
    children,
    ...props
}) {
    // The `active` flag just swaps class strings — no extra element, no logic.
    return (
        <Link
            {...props}
            className={`inline-flex items-center px-2 py-1 text-sm font-medium transition duration-150 ease-in-out focus:outline-none ${
                active
                    ? 'text-white underline decoration-indigo-400 decoration-2'
                    : 'text-white/80 hover:text-white'
            } ${className}`}
        >
            {children}
        </Link>
    );
}
