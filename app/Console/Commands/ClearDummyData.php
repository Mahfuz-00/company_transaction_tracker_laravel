<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Flush all dummy / sample data so the platform can start fresh.
 *
 * Transactional (ledger) data and the roster are wiped, while the STRUCTURAL
 * backbone is preserved:
 *   - roles & permissions (RBAC),
 *   - currencies,
 *   - institutions (the workspaces themselves),
 *   - the user accounts tied to institutions.
 *
 * Usage:
 *   php artisan db:clear-dummy            # prompts for confirmation
 *   php artisan db:clear-dummy --force    # no prompt
 *   php artisan db:clear-dummy --users    # ALSO delete non-super-admin users
 */
class ClearDummyData extends Command
{
    protected $signature = 'db:clear-dummy
        {--force : Skip the confirmation prompt}
        {--users : Also delete non-admin user accounts (keeps Super Admins + Institution Admins)}';

    protected $description = 'Clear transactional dummy data (ledgers, roster, claims, logs) while preserving System Settings, Funding Sources, institutions and base admin users.';

    /**
     * Transactional / dummy tables wiped, in FK-safe order (children first).
     *
     * RETENTION RULE - these are NEVER in the list and are therefore preserved:
     *   - institutions                 (the workspaces / system config)
     *   - subsidy_sources              (Funding Sources - foundational config)
     *   - meal_rate_settings           (System Settings - rate configuration)
     *   - currencies, roles, permissions
     *   - Super Admin / base admin users
     */
    protected array $flush = [
        // Ledger + activity (pure transactional records).
        'meal_entries',
        'meal_expenses',
        'deposits',
        'claims',
        'subsidies',
        'transactions',
        'member_invitations',
        'email_logs',
        'activity_logs',
        'notifications',
        // Test roster + test suppliers + their groupings.
        'students',
        'vendors',
        'departments',
        // NOTE: 'subsidy_sources' (Funding Sources) and 'meal_rate_settings'
        // (System Settings) are intentionally NOT cleared - they are the core
        // configuration the platform must retain.
    ];

    public function handle(): int
    {
        if (! $this->option('force')) {
            $this->warn('This will DELETE all dummy data: members, deposits, meals, expenses, claims, vendors, logs.');
            if (! $this->confirm('Continue?', false)) {
                $this->info('Aborted. Nothing was changed.');

                return self::SUCCESS;
            }
        }

        $this->newLine();
        $this->info('Clearing dummy data...');

        // Foreign-key checks must be off while truncating in a mixed order.
        Schema::disableForeignKeyConstraints();

        foreach ($this->flush as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            try {
                DB::table($table)->truncate();
                $this->line("  cleared <comment>{$table}</comment>");
            } catch (\Throwable $e) {
                // Fall back to a delete if truncate is refused (e.g. SQLite FK).
                DB::table($table)->delete();
                $this->line("  cleared <comment>{$table}</comment> (delete)");
            }
        }

        // Optionally remove the test/staff accounts too, but ALWAYS keep the
        // BASE ADMIN USERS - Super Admins and Institution Admins - so the
        // platform's administrative spine is never destroyed.
        if ($this->option('users')) {
            $baseAdminIds = DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->whereIn('roles.name', ['Software Super Admin', 'Institution Admin'])
                ->pluck('model_has_roles.model_id')
                ->all();

            /*
             * ALWAYS keep the permanent platform owner, even if its role row is
             * momentarily missing (e.g. mid-migration). The SSA account
             * (admin@mahfuz.com) is append-only and must NEVER be deleted by a
             * data reset - only `--users` is even capable of touching accounts,
             * and even then this account is pinned.
             */
            $protectedIds = array_values(array_unique(array_merge(
                $baseAdminIds,
                DB::table('users')
                    ->where('email', \Database\Seeders\SoftwareSuperAdminSeeder::EMAIL)
                    ->pluck('id')
                    ->all(),
            )));

            $deleted = DB::table('users')->whereNotIn('id', $protectedIds)->delete();
            $this->line("  cleared <comment>users</comment> ({$deleted} removed; Super Admins + Institution Admins + the platform owner kept)");
        }

        Schema::enableForeignKeyConstraints();

        $this->newLine();
        $this->info('Dummy data cleared. System Settings, Funding Sources, roles, currencies, institutions and base admin users were preserved.');

        return self::SUCCESS;
    }
}
