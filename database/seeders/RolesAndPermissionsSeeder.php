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
        // Define permissions grouped by module
        $definitions = [
            'transactions' => [
                'transactions.view', 'transactions.create', 'transactions.edit', 'transactions.delete'
            ],
            'meals' => [
                'meals.view', 'meals.entry', 'meals.manage'
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

        // Meal Manager gets most transaction and meal permissions
        $managerPerms = array_merge($definitions['transactions'], $definitions['meals'], ['users.view']);
        $manager->syncPermissions($managerPerms);

        // Student gets limited view permissions
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
