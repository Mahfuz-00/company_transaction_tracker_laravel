/**
 * roleFormatters.js - human-readable labels for permission and role names.
 *
 * WHY IT EXISTS
 * The backend speaks in dotted permission keys ("transactions.view") and raw
 * role names, but the UI needs friendly strings - and it needs them in one
 * place so an unlisted permission still degrades gracefully. This module is
 * pure (no React, no side effects), so it is safe to call from anywhere.
 *
 * PUBLIC API
 *  - formatPermissionLabel(permissionName): maps a known permission key to its
 *    label via the lookup table, falling back to a title-cased version of the
 *    key ("foo.bar" -> "Foo bar") when no entry exists.
 *  - formatRoleName(roleName): title-cases each word of a role name
 *    ("senior admin" -> "Senior Admin"); returns '' for a falsy name.
 */

export function formatPermissionLabel(permissionName) {
    const labels = {
        'transactions.view': 'View Transactions',
        'transactions.create': 'Create Transactions',
        'transactions.edit': 'Edit Transactions',
        'transactions.delete': 'Delete Transactions',
        'meals.view': 'View Meals',
        'meals.entry': 'Enter Meals',
        'meals.manage': 'Manage Meals',
        'meals.deposit': 'Record Deposits',
        'meals.expense': 'Record Expenses',
        'meals.reports': 'View Meal Reports',
        'students.view': 'View Students',
        'students.manage': 'Manage Students',
        'departments.view': 'View Departments',
        'departments.manage': 'Manage Departments',
        'users.view': 'View Users',
        'users.create': 'Create Users',
        'users.edit': 'Edit Users',
        'users.delete': 'Delete Users',
        'roles.view': 'View Roles',
        'roles.manage': 'Manage Roles',
    };

    // Fallback: turns "transactions.view" into "Transactions view" if not found
    return labels[permissionName] || permissionName.replace('.', ' ').replace(/^./, str => str.toUpperCase());
}

export function formatRoleName(roleName) {
    if (!roleName) return '';
    return roleName
        .split(' ')
        .map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
        .join(' ');
}