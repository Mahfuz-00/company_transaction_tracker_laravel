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
                'students.view', 'students.manage',
            ],
            'departments' => [
                'departments.view', 'departments.manage',
            ],
            'vendors' => [
                'vendors.view', 'vendors.manage',
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
        ];

        $allPermissions = [];

        foreach ($definitions as $module => $perms) {
            foreach ($perms as $perm) {
                $p = Permission::firstOrCreate(['name' => $perm], ['guard_name' => 'web']);
                $p->update(['module' => $module]);
                $allPermissions[] = $p->name;
            }
        }

        // Create roles
        $super = Role::firstOrCreate(['name' => 'Super Admin']);
        $manager = Role::firstOrCreate(['name' => 'Meal Manager']);
        $student = Role::firstOrCreate(['name' => 'Student']);

        // Super Admin gets all permissions
        $super->syncPermissions($allPermissions);

        // Meal Manager runs the mess: full transaction + meal rights, plus the
        // ability to see the roster. Deliberately excludes roles.manage and
        // users.create/delete so an admin can still revoke access.
        $managerPerms = array_merge(
            $definitions['transactions'],
            $definitions['meals'],
            $definitions['students'],
            $definitions['departments'],
            // Vendors are operational data a manager needs; institution
            // configuration stays with Super Admin.
            $definitions['vendors'],
            ['users.view', 'institution.view']
        );
        $manager->syncPermissions($managerPerms);

        // Student gets limited view permissions only.
        $student->syncPermissions(['meals.view', 'transactions.view']);

        // Assign Super Admin to first seed user (if exists)
        $user = User::first();
        if ($user) {
            if (! $user->hasRole($super->name)) {
                $user->assignRole($super->name);
            }
        }
    }
}
