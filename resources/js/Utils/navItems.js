/**
 * Navigation map for the app sidebar.
 *
 * Single source of truth for what appears in the sidebar and under what
 * permission. The Sidebar component is a dumb renderer over this structure,
 * so adding a menu item never means touching JSX.
 *
 * Each item shape:
 *   {
 *     label:      string   - text shown in the sidebar
 *     route:      string   - Laravel route() name
 *     icon:       string   - Icon component key (see Components/Icon.jsx)
 *     permission: string | string[] | null
 *                          - null/omitted        -> always visible (all authed users)
 *                          - 'users.view'        -> requires that permission
 *                          - ['a','b']           -> requires ALL of them
 *     match:      string   - route().current() pattern for the active state.
 *                            Defaults to `route`. Use a wildcard like
 *                            'settings.roles.*' to highlight while on child pages.
 *     children:   array    - nested items (rendered as a collapsible group)
 *     roles:      string[] - optional extra gate: visible if user has ANY of these roles
 *   }
 *
 * Permission strings must match database/seeders/RolesAndPermissionsSeeder.php.
 * A typo here silently hides the item, so keep them in sync.
 */

export const NAV_SECTIONS = [
    {
        // No permission -> every signed-in user sees these.
        items: [
            {
                label: 'Dashboard',
                route: 'dashboard',
                icon: 'dashboard',
                permission: null,
            },
            {
                label: 'Analytics',
                route: 'analytics',
                icon: 'analytics',
                permission: null,
            },
            // The standalone "Add Transaction" entry is gone: money in is a
            // Deposit and money out is an Expense, both inside Meal Management.
        ],
    },
    {
        heading: 'Meal Management',
        items: [
            {
                // termKey lets the label follow the institution type
                // (Students / Employees / Boarders) with no code change.
                label: 'Members',
                termKey: 'members',
                route: 'meals.students.index',
                match: 'meals.students.*',
                icon: 'users',
                permission: 'students.view',
            },
            {
                label: 'Subsidies',
                route: 'meals.subsidies.index',
                match: 'meals.subsidies.*',
                icon: 'bank',
                permission: 'subsidies.view',
            },
            {
                label: 'Departments',
                termKey: 'departments',
                route: 'meals.departments.index',
                icon: 'building',
                permission: 'departments.view',
            },
            {
                label: 'Deposits',
                termKey: 'deposits',
                route: 'meals.deposits.index',
                icon: 'download',
                permission: 'meals.deposit',
            },
            {
                label: 'Meal Entries',
                route: 'meals.entries.index',
                icon: 'clipboard',
                permission: 'meals.entry',
            },
            {
                label: 'Expenses',
                route: 'meals.expenses.index',
                icon: 'receipt',
                permission: 'meals.expense',
            },
            {
                label: 'Vendors',
                route: 'meals.vendors.index',
                match: 'meals.vendors.*',
                icon: 'store',
                permission: 'vendors.view',
            },
            {
                label: 'Meal Reports',
                route: 'meals.reports.index',
                icon: 'chart',
                permission: 'meals.reports',
            },
        ],
    },
    {
        heading: 'Administration',
        items: [
            {
                label: 'Settings',
                icon: 'settings',
                // Group is visible if the user can reach ANY child. The renderer
                // prunes children first, then hides the group if nothing remains.
                permission: null,
                children: [
                    {
                        label: 'Institution',
                        route: 'settings.institution.edit',
                        match: 'settings.institution.*',
                        icon: 'bank',
                        permission: 'institution.view',
                    },
                    {
                        label: 'Currency Manager',
                        route: 'settings.currency',
                        match: 'settings.currency',
                        // The route itself is auth-only, but the Administration
                        // section is staff-only UI. users.view is the proxy for
                        // "trusted staff", so members never see this group.
                        permission: 'users.view',
                    },
                    {
                        label: 'Subsidy Sources',
                        route: 'settings.subsidy-sources.index',
                        match: 'settings.subsidy-sources.*',
                        permission: 'subsidies.manage',
                    },
                    {
                        // Software Super Admin only: every institution on the
                        // platform, with its administrators.
                        label: 'Institution Registry',
                        route: 'settings.institutions.index',
                        match: 'settings.institutions.*',
                        permission: 'institutions.view',
                    },
                    {
                        label: 'Role Manager',
                        route: 'settings.roles.index',
                        match: 'settings.roles.*',
                        permission: 'roles.view',
                    },
                    {
                        label: 'User Manager',
                        route: 'settings.users.index',
                        match: 'settings.users.*',
                        permission: 'users.view',
                    },
                    {
                        // Audit trail: visible to Software Super Admins (global)
                        // and Institution Admins (their own institution).
                        label: 'Activity Log',
                        route: 'settings.activity.index',
                        match: 'settings.activity.*',
                        icon: 'clipboard',
                        permission: 'audit.view',
                    },
                ],
            },
        ],
    },
];

/**
 * Filter the nav tree down to what this user is allowed to see.
 * A group with children keeps only the permitted children, and disappears
 * entirely if none survive.
 *
 * @param {function(string|string[]): boolean} isAllowed - predicate from useCan
 */
export function buildVisibleNav(sections, { can, hasRole } = {}) {
    if (typeof can !== 'function') return [];

    const allows = (permission, roles) => {
        if (Array.isArray(permission)) {
            // Array means ALL required.
            return permission.length > 0 && permission.every((p) => can(p));
        }
        if (permission && !can(permission)) return false;

        if (Array.isArray(roles) && roles.length > 0) {
            return typeof hasRole === 'function' && roles.some((r) => hasRole(r));
        }

        return true;
    };

    return sections
        .map((section) => {
            const items = (section.items || [])
                .map((item) => {
                    if (!allows(item.permission, item.roles)) return null;

                    if (Array.isArray(item.children)) {
                        const children = item.children.filter((child) =>
                            allows(child.permission, child.roles)
                        );

                        // Group is only worth showing if it leads somewhere.
                        if (children.length === 0) return null;

                        return { ...item, children };
                    }

                    return item;
                })
                .filter(Boolean);

            return { ...section, items };
        })
        .filter((section) => section.items.length > 0);
}
