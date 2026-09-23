<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * PERMANENT SOFTWARE SUPER ADMIN GUARD.
 *
 * WHAT IT GUARANTEES
 * ------------------
 * The platform owner account (`admin@mahfuz.com`) must ALWAYS exist, forever.
 * This seeder is idempotent and safe to run any number of times:
 *
 *   1. It ensures the global `Software Super Admin` role exists.
 *   2. It CREATES the account only when it is MISSING. It never updates an
 *      existing row, so a password that has already been set is never
 *      overwritten, and `password_changed_at` is never clobbered.
 *   3. It re-asserts the global role if the account somehow lost it (a role
 *      assignment is not a credential, so this is safe to re-apply).
 *
 * It NEVER deletes anything and never touches transactional tables - unlike
 * `db:clear-dummy`, which deliberately clears ledger/roster data.
 *
 * BOOTSTRAP PASSWORD
 * ------------------
 * On FIRST creation the password comes from the `SSA_BOOTSTRAP_PASSWORD` env var.
 * If that is unset the account is created with a NULL password (the `users`
 * table allows this) and must be given one through the audited path:
 *
 *     php artisan ssa:reset-password admin@mahfuz.com
 *
 * That keeps a hardcoded credential out of the repository entirely.
 *
 * WHERE IT RUNS
 * -------------
 * Registered last in `DatabaseSeeder`, so `php artisan db:seed` /
 * `migrate --seed` provision it, and `composer setup` seeds it on a fresh
 * install.
 */
class SoftwareSuperAdminSeeder extends Seeder
{
    /** The permanent platform-owner account. */
    public const EMAIL = 'admin@mahfuz.com';

    /** Display name for the platform owner. */
    public const NAME = 'Mahfuz';

    /** The global (cross-tenant) role this account holds. */
    public const ROLE = 'Software Super Admin';

    public function run(): void
    {
        // 1. The global role must exist before it can be assigned.
        $role = Role::firstOrCreate(
            ['name' => self::ROLE, 'guard_name' => 'web'],
        );

        // 2. CREATE ONLY IF MISSING. `first()` + `create()` (rather than
        //    `updateOrCreate`) is deliberate: an existing account is left
        //    completely untouched, so its hashed password survives every seed.
        $user = User::where('email', self::EMAIL)->first();

        if (! $user) {
            $attributes = [
                // A Software Super Admin is GLOBAL: no institution binding.
                'institution_id' => null,
                'name' => self::NAME,
                'email' => self::EMAIL,
                'status' => 'active',
                'must_change_password' => false,
                'setup_completed_at' => now(),
            ];

            // Only set a password when one was explicitly provided. The column
            // is nullable, so omitting the key leaves it NULL rather than
            // inventing a credential. The User model's `hashed` cast hashes the
            // plain value for us.
            $bootstrap = (string) env('SSA_BOOTSTRAP_PASSWORD', '');
            if ($bootstrap !== '') {
                $attributes['password'] = $bootstrap;
            }

            $user = User::create($attributes);

            $this->command?->info('  Software Super Admin created: ' . self::EMAIL);

            if ($bootstrap === '') {
                $this->command?->warn(
                    '  No SSA_BOOTSTRAP_PASSWORD set - set one with: '
                    . 'php artisan ssa:reset-password ' . self::EMAIL
                );
            }
        }

        // 3. Re-assert the role (idempotent; touches no credentials).
        if (! $user->hasRole($role->name)) {
            $user->assignRole($role->name);
            $this->command?->info('  Re-granted the global role to ' . self::EMAIL . '.');
        }
    }
}
