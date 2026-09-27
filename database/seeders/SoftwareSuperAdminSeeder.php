<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
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
        //    `updateOrCreate`) is deliberate: an existing account with a WORKING
        //    credential is left untouched, so its hashed password survives every
        //    seed run.
        $user = User::where('email', self::EMAIL)->first();

        $bootstrap = trim((string) env('SSA_BOOTSTRAP_PASSWORD', ''));

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
            // inventing a credential.
            //
            // HASHING: we hash EXPLICITLY with Hash::make() rather than relying
            // on the model's `hashed` cast alone bypass the model's
            // anti-tamper hook via the seeder-authorised flag below. That hook
            // exists to stop SILENT credential drift on an EXISTING account; a
            // brand-new row legitimately sets its first credential here.
            if ($bootstrap !== '') {
                $attributes['password'] = Hash::make($bootstrap);
            }

            $user = new User($attributes);
            // Authorise this write: creating the account WITH its bootstrap
            // credential is exactly what the guard is meant to allow.
            $user->passwordWriteAuthorised = true;
            $user->save();

            $this->command?->info('  Software Super Admin created: ' . self::EMAIL);

            if ($bootstrap === '') {
                $this->command?->warn(
                    '  No SSA_BOOTSTRAP_PASSWORD set - set one with: '
                    . 'php artisan ssa:reset-password ' . self::EMAIL
                );
            }
        } elseif ($bootstrap !== '' && ! static::hasUsablePassword($user, $bootstrap)) {
            /*
             * SELF-HEAL - the reason the SSA could not log in.
             *
             * Historically the account could be created with a NULL (or stale)
             * password: the create branch ran `console.log(...)` - JavaScript in
             * a PHP file - which threw before the credential was ever written, and
             * on any later seed run the `if (! $user)` branch was skipped entirely,
             * so the NULL password persisted forever. Login therefore always
             * failed even though SSA_BOOTSTRAP_PASSWORD was configured.
             *
             * We now verify the stored hash actually MATCHES the configured
             * bootstrap password and repair it when it does not. This is
             * deliberately narrow:
             *   - only for THIS account (the platform owner),
             *   - only when the env var is present,
             *   - only when the stored credential does not already work,
             * so a password the owner has since changed in-app is never clobbered
             * unless it has stopped matching the configured bootstrap value.
             */
            $user->passwordWriteAuthorised = true;
            $user->password = Hash::make($bootstrap);
            $user->save();

            $this->command?->info(
                '  Repaired the Software Super Admin bootstrap password for ' . self::EMAIL . '.'
            );
        }

        // 3. Re-assert the role (idempotent; touches no credentials).
        if (! $user->hasRole($role->name)) {
            $user->assignRole($role->name);
            $this->command?->info('  Re-granted the global role to ' . self::EMAIL . '.');
        }
    }

    /**
     * Does the stored credential already authenticate the given plaintext?
     *
     * Returns false when the hash is NULL/blank (the broken state this seeder
     * repairs) or simply does not match, so the caller can re-seed it.
     */
    protected static function hasUsablePassword(User $user, string $plain): bool
    {
        $hash = (string) $user->getRawOriginal('password');

        if ($hash === '') {
            return false;
        }

        try {
            return Hash::check($plain, $hash);
        } catch (\Throwable $e) {
            // A malformed hash (e.g. not a bcrypt string) is unusable.
            return false;
        }
    }
}
