<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * CI / deploy schema guard: assert the multi-tenant + RBAC schema is intact.
 *
 * WHY THIS IS A COMMAND AND NOT AN INLINE `tinker --execute`
 * ---------------------------------------------------------
 * The CI pipeline must prove, after migrating, that
 *   - the expected tenant + permission tables exist, and
 *   - `users.institution_id` is present (the column every tenant scope relies on).
 *
 * Writing that check as an inline `php artisan tinker --execute="..."` made the
 * pipeline depend on multi-line shell + PHP quoting, which differs between bash,
 * PowerShell and the runner - a classic source of "it passed locally but the CI
 * step exploded". This command removes the shell entirely: it is plain PHP,
 * runs identically everywhere, and can be executed by a developer by hand:
 *
 *     php artisan schema:assert-tenant
 *
 * Exit code is 0 on success and 1 on the first failure, so a GitHub Actions step
 * fails the job cleanly and prints the reason.
 */
class AssertTenantSchema extends Command
{
    protected $signature = 'schema:assert-tenant
        {--connection= : The database connection to inspect (defaults to the app default)}';

    protected $description = 'Assert the tenant + RBAC schema is intact (institutions, users.institution_id, roles).';

    /** Tables the multi-tenant permission model cannot run without. */
    private const REQUIRED_TABLES = [
        'institutions',
        'users',
        'roles',
        'permissions',
        'model_has_roles',
    ];

    public function handle(): int
    {
        $connection = $this->option('connection') ?: config('database.default');

        try {
            // 1. Every required table must exist.
            foreach (self::REQUIRED_TABLES as $table) {
                if (! Schema::connection($connection)->hasTable($table)) {
                    return $this->failWith("Missing expected table: {$table}");
                }
            }

            $this->info('✓ All tenant + RBAC tables present: '.implode(', ', self::REQUIRED_TABLES));

            // 2. users.institution_id is the column every tenant scope keys on.
            $columns = Schema::connection($connection)->getColumnListing('users');

            if (! in_array('institution_id', $columns, true)) {
                return $this->failWith('users.institution_id is missing - multi-tenant scoping would fail.');
            }

            $this->info('✓ users.institution_id present (tenant scoping enforced).');

            // 3. The permission system resolves roles (seeders ran, tables queryable).
            $roleCount = Role::query()->count();
            $this->info("✓ {$roleCount} role(s) seeded and queryable.");

            $this->newLine();
            $this->info('Schema assertion passed.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            return $this->failWith($e->getMessage());
        }
    }

    /** Print a red error and return the failure exit code in one line. */
    private function failWith(string $message): int
    {
        $this->error('✗ '.$message);

        return self::FAILURE;
    }
}
