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

            return self::SUCCESS;
        }

        $this->info('Fresh database detected - seeding baseline data...');

        $this->call('db:seed', ['--force' => true]);

        return self::SUCCESS;
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
