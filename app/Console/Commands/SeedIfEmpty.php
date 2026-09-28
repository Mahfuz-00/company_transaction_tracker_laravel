<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Deploy-safe seeding: seed the baseline data ONLY when the database has never
 * been seeded, otherwise do nothing.
 *
 * WHY
 * ---
 * The production container previously ran `php artisan migrate --force --seed`
 * on every boot, so a REDEPLOY re-ran the seeders against a live database. The
 * seeders are idempotent today, but relying on that forever is fragile - a
 * future seeder that is not idempotent could corrupt production data. This
 * command makes the guarantee explicit and testable:
 *
 *   - a fresh database (no permissions yet)  -> seeds the baseline,
 *   - an existing database (permissions set) -> refuses to touch anything.
 *
 * It is intentionally plain PHP (no `tinker --execute` shell quoting), so it
 * behaves identically in bash, PowerShell and CI. Run it directly with:
 *
 *     php artisan db:seed-if-empty
 *
 * NOTE ON THE "ALREADY SEEDED" PATH
 * ---------------------------------
 * Even when the baseline is skipped, the command still re-asserts the permanent
 * platform owner (admin@mahfuz.com) through SoftwareSuperAdminSeeder. That guard
 * is idempotent and cannot overwrite a working password, so running it on every
 * boot is safe - and it is what stops a database from silently ending up with an
 * unusable operator account. See `php artisan ssa:doctor` for the diagnosis of
 * every other way that account can break.
 */
class SeedIfEmpty extends Command
{
    protected $signature = 'db:seed-if-empty
        {--connection= : The database connection to inspect (defaults to the app default)}';

    protected $description = 'Seed baseline data only when the database is unseeded (idempotent, non-destructive).';

    public function handle(): int
    {
        $connection = $this->option('connection') ?: config('database.default');

        if ($this->alreadySeeded($connection)) {
            $this->info('Database already seeded - skipping seeders (no data touched).');

            /*
             * A SKIPPED SEED MUST STILL GUARANTEE THE PLATFORM OWNER.
             *
             * The whole point of this command is that it is safe to run on every
             * boot. But "skip everything" also meant the SSA account was never
             * checked - so a database whose owner row had lost its role (or was
             * created with a NULL password) stayed broken until an operator
             * manually ran `db:seed`. That is exactly the manual intervention
             * this platform is supposed to eliminate.
             *
             * We therefore ALWAYS re-run the SSA guard, which is idempotent and
             * strictly non-destructive: it creates the account only when it is
             * missing, never overwrites a working password, and only re-asserts
             * the role assignment. It touches no transactional table.
             */
            $this->ensurePlatformOwner();

            return self::SUCCESS;
        }

        $this->info('Fresh database detected - seeding baseline data...');

        $this->call('db:seed', ['--force' => true]);

        return self::SUCCESS;
    }

    /**
     * Re-assert the permanent platform owner without touching credentials.
     *
     * Delegates to SoftwareSuperAdminSeeder so the creation rules live in ONE
     * place; this method only decides WHEN it runs (every boot, not just the
     * first one).
     */
    protected function ensurePlatformOwner(): void
    {
        try {
            $this->call('db:seed', [
                '--class' => \Database\Seeders\SoftwareSuperAdminSeeder::class,
                '--force' => true,
            ]);
        } catch (Throwable $e) {
            // Never fail a boot because the guard could not run - report and
            // continue. The operator can inspect with `php artisan ssa:doctor`.
            $this->warn('  Could not verify the platform owner account: ' . $e->getMessage());
            $this->line('  Diagnose with: php artisan ssa:doctor');
        }
    }

    /**
     * Has the baseline data been seeded?
     *
     * We key off the `permissions` table, NOT `roles`: the roles are also
     * created by the `streamline_core_roles` MIGRATION (so a fresh `migrate`
     * leaves four role rows with zero permissions). Permissions - and the
     * role→permission grants - are written only by the seeder, so an empty
     * `permissions` table is the reliable "this install has never been seeded"
     * signal.
     */
    protected function alreadySeeded(string $connection): bool
    {
        try {
            if (! Schema::connection($connection)->hasTable('permissions')) {
                return false;
            }

            return DB::connection($connection)->table('permissions')->exists();
        } catch (Throwable $e) {
            // If we cannot tell, assume NOT seeded: seeding is idempotent, so a
            // false negative is safe while a false positive would leave a fresh
            // install without its permissions.
            return false;
        }
    }
}
