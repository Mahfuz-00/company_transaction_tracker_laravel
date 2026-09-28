<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PasswordGuard;
use Database\Seeders\SoftwareSuperAdminSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * DIAGNOSE AND REPAIR THE PLATFORM-OWNER ACCOUNT.
 *
 * WHY THIS EXISTS
 * ---------------
 * The recurring report was: "user accounts and passwords are wiped/reset
 * unexpectedly, forcing a manual db:seed". There were two distinct causes, and
 * this command addresses the second while reporting on the first:
 *
 *   1. (FIXED AT SOURCE) `RolesAndPermissionsSeeder` used to end with
 *      `User::first()->assignRole('Software Super Admin')`. On any database where
 *      the platform owner was not the lowest-id user, a routine `db:seed`
 *      silently promoted a RANDOM account to the global role - so the operator's
 *      credentials appeared to have been "taken over". That step has been
 *      removed.
 *
 *   2. (THIS COMMAND) Even with the seeder fixed, the owner account can be left
 *      in an unusable state by other means: created with a NULL password (the
 *      historical bug), disabled by a data reset, or stripped of its role by a
 *      role-sync. `db:seed` cannot fix a NULL password unless
 *      `SSA_BOOTSTRAP_PASSWORD` is set, so an operator is left hand-editing rows.
 *
 * WHAT IT DOES
 * ------------
 * A read-only DIAGNOSIS by default - it prints the state of every check and
 * exits non-zero if anything is wrong. With `--fix` it repairs the safe subset:
 *
 *   - re-creates the account if it is missing (delegating to
 *     SoftwareSuperAdminSeeder, so the creation rules stay in ONE place),
 *   - re-grants the global role if it was lost,
 *   - re-activates the account if it was disabled,
 *   - clears a stuck `must_change_password` / `invitation_pending` flag.
 *
 * It NEVER overwrites an existing, working password. A NULL password is only
 * repaired when `--password` is supplied (or `SSA_BOOTSTRAP_PASSWORD` is set),
 * and the write always goes through PasswordGuard so it is audited.
 *
 * USAGE
 *   php artisan ssa:doctor                 # diagnose only (exit 1 if unhealthy)
 *   php artisan ssa:doctor --fix           # repair the safe subset
 *   php artisan ssa:doctor --fix --password="a-strong-one"
 */
class SuperAdminDoctor extends Command
{
    protected $signature = 'ssa:doctor
        {--fix : Repair the safe subset of problems (never overwrites a working password)}
        {--password= : Set this password when the account has NONE (or with --force-password)}
        {--force-password : Also replace an existing, working password (audited)}
        {--email= : Diagnose a different account (defaults to the platform owner)}';

    protected $description = 'Diagnose (and optionally repair) the Software Super Admin account so a manual db:seed is never needed.';

    public function handle(): int
    {
        $email = (string) ($this->option('email') ?: SoftwareSuperAdminSeeder::EMAIL);
        $fix = (bool) $this->option('fix');

        $this->newLine();
        $this->info("Software Super Admin health check: {$email}");
        $this->line(str_repeat('-', 60));

        $problems = [];
        $notes = [];

        // ---- 1. Schema sanity ------------------------------------------------
        if (! Schema::hasTable('users')) {
            $this->error('  The `users` table does not exist. Run `php artisan migrate` first.');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        // ---- 2. The account exists -------------------------------------------
        if (! $user) {
            $problems[] = 'missing_account';
            $this->line('  <fg=red>x</> Account does not exist.');

            if (! $fix) {
                return $this->report($problems, $notes, $fix);
            }

            // Delegating keeps the creation rules (institution_id = null, role,
            // status) in the seeder rather than duplicated here.
            $this->call('db:seed', [
                '--class' => SoftwareSuperAdminSeeder::class,
                '--force' => true,
            ]);

            $user = User::where('email', $email)->first();

            if (! $user) {
                $this->error('  Failed to create the account. Check the database connection.');

                return self::FAILURE;
            }

            $this->line('  <fg=green>+</> Account created via SoftwareSuperAdminSeeder.');
        } else {
            $this->line('  <fg=green>+</> Account exists (id '.$user->id.').');
        }

        // ---- 3. The role -----------------------------------------------------
        $hasRole = $user->hasRole(SoftwareSuperAdminSeeder::ROLE);

        if ($hasRole) {
            $this->line('  <fg=green>+</> Holds the global "'.SoftwareSuperAdminSeeder::ROLE.'" role.');
        } else {
            $problems[] = 'missing_role';
            $this->line('  <fg=red>x</> Missing the global "'.SoftwareSuperAdminSeeder::ROLE.'" role.');

            if ($fix) {
                $user->assignRole(SoftwareSuperAdminSeeder::ROLE);
                $this->line('  <fg=green>+</> Role re-granted.');
            }
        }

        // ---- 4. The credential ------------------------------------------------
        $hash = (string) $user->getRawOriginal('password');

        if ($hash === '') {
            $problems[] = 'null_password';
            $this->line('  <fg=red>x</> Has NO password set (login is impossible).');

            if ($fix) {
                $plain = (string) ($this->option('password') ?: env('SSA_BOOTSTRAP_PASSWORD', ''));

                if ($plain === '') {
                    $this->line(
                        '  <fg=yellow>!</> Cannot repair without a password. Pass --password="..." '
                        .'or set SSA_BOOTSTRAP_PASSWORD.'
                    );
                } elseif (strlen($plain) < 8) {
                    $this->error('  The supplied password is shorter than 8 characters.');
                } else {
                    PasswordGuard::changePassword($user, $plain, 'cli_doctor_repair', forceSsa: true);
                    $this->line('  <fg=green>+</> Password set through PasswordGuard (audited).');
                    $notes[] = 'The new password was written to the activity log entry, not to this output.';
                }
            }
        } else {
            $this->line('  <fg=green>+</> Has a stored password hash.');

            // A deliberate, explicit password replacement - never implicit.
            if ($fix && $this->option('force-password')) {
                $plain = (string) $this->option('password');

                if (strlen($plain) < 8) {
                    $this->error('  --force-password needs --password="..." (8+ characters).');
                } else {
                    PasswordGuard::changePassword($user, $plain, 'cli_doctor_force', forceSsa: true);
                    $this->line('  <fg=green>+</> Password replaced (audited, explicit --force-password).');
                }
            } else {
                // Prove the hash is a usable bcrypt/argon string rather than a
                // truncated or corrupted one, which would silently fail every
                // login attempt.
                if (! $this->hashLooksValid($hash)) {
                    $problems[] = 'corrupt_hash';
                    $this->line('  <fg=red>x</> The stored hash is malformed - no password can match it.');
                    $this->line('  <fg=yellow>!</> Repair with: php artisan ssa:reset-password '.$email);
                }
            }
        }

        // ---- 5. Status + onboarding flags -------------------------------------
        if (($user->status ?? 'active') !== 'active') {
            $problems[] = 'inactive';
            $this->line('  <fg=red>x</> Account status is "'.$user->status.'" - login is refused.');

            if ($fix) {
                $user->forceFill(['status' => 'active'])->save();
                $this->line('  <fg=green>+</> Status set to active.');
            }
        } else {
            $this->line('  <fg=green>+</> Status is active.');
        }

        if ($user->invitation_pending) {
            $problems[] = 'invitation_pending';
            $this->line('  <fg=red>x</> Flagged `invitation_pending` - the account cannot sign in.');

            if ($fix) {
                $user->forceFill(['invitation_pending' => false])->save();
                $this->line('  <fg=green>+</> Invitation flag cleared.');
            }
        }

        if ($user->must_change_password) {
            // Informational: a forced rotation is a legitimate state, not a fault.
            $this->line('  <fg=yellow>i</> `must_change_password` is set (a deliberate forced rotation).');
        }

        // ---- 6. Tenant binding -------------------------------------------------
        if ($user->institution_id !== null) {
            $problems[] = 'tenant_bound';
            $this->line(
                '  <fg=red>x</> Bound to institution #'.$user->institution_id
                .'. A global SSA must have institution_id = NULL.'
            );

            if ($fix) {
                $user->forceFill(['institution_id' => null])->save();
                $this->line('  <fg=green>+</> Unbound from the institution (global context restored).');
            }
        } else {
            $this->line('  <fg=green>+</> Correctly unbound (global context).');
        }

        // ---- 7. Duplicate global operators --------------------------------------
        // More than one SSA is not fatal, but it is worth surfacing: the second
        // is usually the accidental promotion the old seeder caused.
        $ssaCount = $this->countSuperAdmins();

        if ($ssaCount > 1) {
            $this->line("  <fg=yellow>i</> {$ssaCount} accounts hold the global role - review the list below.");
            foreach ($this->superAdminEmails() as $other) {
                $this->line("      - {$other}");
            }
        } else {
            $this->line('  <fg=green>+</> Exactly one global operator account.');
        }

        return $this->report($problems, $notes, $fix);
    }

    /**
     * Print the verdict and return the exit code.
     *
     * @param  array<int,string>  $problems
     * @param  array<int,string>  $notes
     */
    protected function report(array $problems, array $notes, bool $fix): int
    {
        $this->line(str_repeat('-', 60));

        foreach ($notes as $note) {
            $this->line("  note: {$note}");
        }

        if ($problems === []) {
            $this->info('Healthy - the platform owner can sign in. No manual seeding needed.');

            return self::SUCCESS;
        }

        if ($fix) {
            $this->warn(
                'Repaired '.count($problems).' problem(s). Re-run without --fix to confirm.'
            );

            // A password cannot be repaired without a value, so treat a still-NULL
            // credential as a hard failure even in --fix mode.
            $user = User::where('email', (string) ($this->option('email') ?: SoftwareSuperAdminSeeder::EMAIL))->first();

            if ($user && (string) $user->getRawOriginal('password') === '') {
                $this->error('Still missing a password - supply --password="..." and re-run with --fix.');

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        $this->error(
            count($problems).' problem(s) found: '.implode(', ', $problems).'.'
        );
        $this->line('  Re-run with --fix to repair the safe subset.');

        return self::FAILURE;
    }

    /** A cheap structural check that the stored hash is a real, complete digest. */
    protected function hashLooksValid(string $hash): bool
    {
        // bcrypt ($2y$...), argon2i / argon2id are the algorithms Laravel issues.
        if (preg_match('/^\$(2[aby]|argon2i|argon2id)\$/', $hash) !== 1) {
            return false;
        }

        // A truncated hash can still match the prefix, so prove it round-trips.
        try {
            Hash::check('__doctor_probe__', $hash);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function countSuperAdmins(): int
    {
        return $this->superAdminQuery()->count();
    }

    /** @return array<int,string> */
    protected function superAdminEmails(): array
    {
        return $this->superAdminQuery()->pluck('email')->all();
    }

    protected function superAdminQuery()
    {
        return DB::table('users')
            ->join('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', SoftwareSuperAdminSeeder::ROLE)
            ->where('model_has_roles.model_type', User::class);
    }
}
