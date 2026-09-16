/**
 * Presentation helpers for the User Manager.
 * Kept alongside roleFormatters.js so both settings pages share one source of truth.
 */

/**
 * Deterministic pastel color classes for a role badge, so a given role always
 * renders with the same color across the table and the assign-roles checklist.
 */
export function roleBadgeClasses(roleName) {
    const name = (roleName || '').toLowerCase();

    // Friendly overrides for the seeded roles.
    if (name.includes('super')) {
        return 'bg-rose-50 text-rose-700 border-rose-100';
    }
    if (name.includes('manager') || name.includes('admin')) {
        return 'bg-indigo-50 text-indigo-700 border-indigo-100';
    }
    if (name.includes('student')) {
        return 'bg-emerald-50 text-emerald-700 border-emerald-100';
    }

    const palette = [
        'bg-sky-50 text-sky-700 border-sky-100',
        'bg-amber-50 text-amber-700 border-amber-100',
        'bg-violet-50 text-violet-700 border-violet-100',
        'bg-teal-50 text-teal-700 border-teal-100',
        'bg-fuchsia-50 text-fuchsia-700 border-fuchsia-100',
    ];

    let hash = 0;
    for (let i = 0; i < name.length; i += 1) {
        hash = (hash * 31 + name.charCodeAt(i)) % 997;
    }

    return palette[hash % palette.length];
}

/**
 * "Farhan Hossain" -> "FH"
 */
export function initials(name) {
    if (!name) return '?';
    return name
        .trim()
        .split(/\s+/)
        .map((part) => part.charAt(0))
        .slice(0, 2)
        .join('')
        .toUpperCase();
}

/**
 * Status pill classes for active / inactive accounts.
 */
export function statusBadgeClasses(status) {
    return (status || 'active') === 'active'
        ? 'bg-emerald-50 text-emerald-700 border-emerald-100'
        : 'bg-slate-100 text-slate-500 border-slate-200';
}

/**
 * "active" -> "Active"
 */
export function formatStatus(status) {
    const value = status || 'active';
    return value.charAt(0).toUpperCase() + value.slice(1).toLowerCase();
}
