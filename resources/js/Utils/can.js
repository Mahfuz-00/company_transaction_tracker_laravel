import { usePage } from '@inertiajs/react';

export default function useCan() {
    const { props } = usePage();
    const permissions = (props && props.auth && props.auth.permissions) || [];
    const roles = (props && props.auth && props.auth.roles) || [];

    function can(permission) {
        if (!permission) return false;
        return permissions.includes(permission);
    }

    function hasRole(roleName) {
        if (!roleName) return false;
        return roles.includes(roleName);
    }

    return { can, hasRole, permissions, roles };
}
