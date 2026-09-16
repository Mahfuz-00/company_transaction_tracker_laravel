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