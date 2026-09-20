<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

/**
 * Streamline roles down to exactly four core roles:
 *
 *   software super admin  - global platform owner
 *   institution admin     - scoped administrator for one institution
 *   meal manager          - runs the day-to-day mess operations
 *   member                - an ordinary end user (formerly "Student")
 *
 * The redundant legacy roles ("Student", plain "Super Admin") are folded into
 * the core set and then removed, so role lists and dropdowns stop drifting.
 */

return new class extends Migration
{
    public function up(): void
    {
        // Roles are created by the seeder on a fresh install; on an existing
        // database they already exist in some form. This migration only needs
        // to move people off the legacy names and delete them.
        $this->ensureCoreRoles();

        $this->migrateUsers('Student', 'Member');
        $this->migrateUsers('Super Admin', 'Software Super Admin');

        // Drop the now-empty legacy roles.
        $this->deleteRoleIfUnused('Student');
        $this->deleteRoleIfUnused('Super Admin');
    }

    public function down(): void
    {
        // Recreating the legacy aliases is enough to reverse the data move.
        Role::firstOrCreate(['name' => 'Student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
    }

    /* ------------------------------------------------------------------ */

    protected function ensureCoreRoles(): void
    {
        foreach (['Software Super Admin', 'Institution Admin', 'Meal Manager', 'Member'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    /**
     * Re-point every user holding $fromRole at $toRole, then detach the old one.
     * Runs on the pivot table directly so it works regardless of model state.
     */
    protected function migrateUsers(string $fromRole, string $toRole): void
    {
        $from = Role::where('name', $fromRole)->where('guard_name', 'web')->first();
        $to = Role::where('name', $toRole)->where('guard_name', 'web')->first();

        if (! $from || ! $to) {
            return;
        }

        $table = config('permission.table_names.model_has_roles', 'model_has_roles');

        if (! Schema::hasTable($table)) {
            return;
        }

        // Users currently on the legacy role.
        $userIds = DB::table($table)
            ->where('role_id', $from->id)
            ->pluck('model_id')
            ->unique();

        foreach ($userIds as $id) {
            // Add the core role if they don't already hold it.
            $already = DB::table($table)
                ->where('role_id', $to->id)
                ->where('model_id', $id)
                ->exists();

            if (! $already) {
                DB::table($table)->insert([
                    'role_id' => $to->id,
                    'model_type' => 'App\\Models\\User',
                    'model_id' => $id,
                ]);
            }
        }

        // Detach the legacy role from everyone.
        DB::table($table)->where('role_id', $from->id)->delete();
    }

    protected function deleteRoleIfUnused(string $name): void
    {
        $role = Role::where('name', $name)->where('guard_name', 'web')->first();

        if (! $role) {
            return;
        }

        $table = config('permission.table_names.model_has_roles', 'model_has_roles');
        $inUse = Schema::hasTable($table)
            && DB::table($table)->where('role_id', $role->id)->exists();

        if (! $inUse) {
            // Clear pivot rows for permissions too, then remove the role.
            DB::table(config('permission.table_names.role_has_permissions', 'role_has_permissions'))
                ->where('role_id', $role->id)
                ->delete();

            $role->delete();
        }
    }
};
