import { usePage } from '@inertiajs/react';

/**
 * Read the current user's roles and permissions, shared from
 * HandleInertiaRequests on every response.
 *
 * Returns:
 *   can(permission)          - has this exact permission?
 *   canAny([p1, p2])         - has at least one of these?
 *   canAll([p1, p2])         - has every one of these?
 *   hasRole(role)            - has this role?
 *   permissions, roles       - the raw arrays
 */
export default function useCan() {
    const { props } = usePage();

    // Defensive: a malformed or missing share must never crash the layout,
    // so coerce anything unexpected into an empty array.
    const rawPermissions = props?.auth?.permissions;
    const rawRoles = props?.auth?.roles;

    const permissions = Array.isArray(rawPermissions) ? rawPermissions : [];
    const roles = Array.isArray(rawRoles) ? rawRoles : [];

    function can(permission) {
        if (!permission) return false;
        return permissions.includes(permission);
    }

    function canAny(required) {
        const list = Array.isArray(required) ? required : [required];
        if (list.length === 0) return false;
        return list.some((permission) => can(permission));
    }

    function canAll(required) {
        const list = Array.isArray(required) ? required : [required];
        if (list.length === 0) return false;
        return list.every((permission) => can(permission));
    }

    function hasRole(roleName) {
        if (!roleName) return false;
        return roles.includes(roleName);
    }

    /**
     * Super Admin bypass. The seeder grants Super Admin every permission, so
     * this is normally redundant - but it keeps the UI correct if a new
     * permission is added to the code before it is seeded.
     */
    // Prefer the authoritative server flag; fall back to the role list so the
    // UI still works if the share is ever missing.
    const isSuperAdmin =
        props?.auth?.user?.is_super_admin === true ||
        roles.includes('Software Super Admin') ||
        roles.includes('Super Admin') ||
        roles.includes('superadmin');

    const isInstitutionAdmin = roles.includes('Institution Admin');

    return {
        can,
        canAny,
        canAll,
        hasRole,
        isSuperAdmin,
        isInstitutionAdmin,
        permissions,
        roles,
    };
}
