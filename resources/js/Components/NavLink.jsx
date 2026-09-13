import { Link } from '@inertiajs/react';

export default function NavLink({
    active = false,
    className = '',
    children,
    ...props
}) {
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
