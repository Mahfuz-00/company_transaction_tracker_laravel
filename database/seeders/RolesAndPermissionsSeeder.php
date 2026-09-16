<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use App\Models\User;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run()
    {
        // Define permissions grouped by module.
        // These names must match the route middleware in routes/web.php and
        // the `permission` keys in resources/js/Utils/navItems.js.
        $definitions = [
            'transactions' => [
                'transactions.view', 'transactions.create', 'transactions.edit', 'transactions.delete'
            ],
            'meals' => [
                'meals.view', 'meals.entry', 'meals.manage',
                'meals.deposit', 'meals.expense', 'meals.reports',
            ],
            'students' => [
                'students.view', 'students.manage', 'students.invite',
            ],
            'departments' => [
                'departments.view', 'departments.manage',
            ],
            'vendors' => [
                'vendors.view', 'vendors.manage',
            ],
            'subsidies' => [
                'subsidies.view', 'subsidies.manage',
            ],
            'exports' => [
                'exports.download',
            ],
            'institution' => [
                'institution.view', 'institution.manage',
            ],
            'users' => [
                'users.view', 'users.create', 'users.edit', 'users.delete'
            ],
            'roles' => [
                'roles.view', 'roles.manage'
            ],
            // Audit trail visibility. Institution Admins get this scoped to
            // their own institution; Super Admins see everything.
            'audit' => [
                'audit.view',
            ],
            // Appearance + branding (logo, avatar, theme).
            'appearance' => [
                'appearance.view', 'appearance.manage',
            ],
            // The super-admin institution registry.
            'institutions' => [
                'institutions.view', 'institutions.manage',
            ],
        ];

        $allPermissions = [];

        foreach ($definitions as $module => $perms) {
            foreach ($perms as $perm) {
                $p = Permission::firstOrCreate(['name' => $perm], ['guard_name' => 'web']);
                $p->update(['module' => $module]);
                $allPermissions[] = $p->name;
            }
        }

        /* -------------------------------------------------------------- *
         * Two-tier admin structure
         *
         *  - Software Super Admin : global. Owns platform settings (currency,
         *    institution type, roles) and sees every institution's audit trail.
         *  - Institution Admin    : scoped to one institution. Runs members,
         *    meals, deposits, subsidies and vendors for their own body, and
         *    sees their own institution's audit trail.
         * -------------------------------------------------------------- */
        // Exactly four core roles - no legacy aliases. Any account still on a
        // removed role was migrated by the streamline_core_roles migration.
        $super = Role::firstOrCreate(['name' => 'Software Super Admin']);
        $instAdmin = Role::firstOrCreate(['name' => 'Institution Admin']);
        $manager = Role::firstOrCreate(['name' => 'Meal Manager']);
        $member = Role::firstOrCreate(['name' => 'Member']);

        // Software Super Admin gets everything, globally.
        $super->syncPermissions($allPermissions);

        // Institution Admin: everything operational, scoped to their body.
        // Deliberately excludes nothing operational - they are the top authority
        // *within* an institution - but they are scoped in the controllers.
        $instAdminPerms = array_merge(
            $definitions['transactions'],
            $definitions['meals'],
            $definitions['students'],
            $definitions['departments'],
            $definitions['vendors'],
            $definitions['subsidies'],
            $definitions['exports'],
            $definitions['audit'],
            $definitions['institution'],
            $definitions['appearance'],
            ['users.view', 'users.create', 'users.edit', 'roles.view']
        );
        $instAdmin->syncPermissions($instAdminPerms);

        // Meal Manager runs the mess: transactions + meals + roster + vendors.
        // Deliberately excludes roles.manage and users.create/delete so an
        // admin can still revoke access. Sees subsidy data but does not manage it.
        $managerPerms = array_merge(
            $definitions['transactions'],
            $definitions['meals'],
            $definitions['students'],
            $definitions['departments'],
            $definitions['vendors'],
            $definitions['exports'],
            ['users.view', 'institution.view', 'subsidies.view', 'audit.view', 'appearance.view']
        );
        $manager->syncPermissions($managerPerms);

        // Member: limited view permissions only - sees their own meals and
        // deposits, nothing administrative.
        $member->syncPermissions(['meals.view', 'transactions.view']);

        // Assign the top role to the first seed user (if exists).
        $user = User::first();
        if ($user) {
            if (! $user->hasRole($super->name)) {
                $user->assignRole($super->name);
            }
        }
    }
}
