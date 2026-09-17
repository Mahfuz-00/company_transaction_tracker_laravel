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
        {--users : Also delete member/staff user accounts (keeps Super Admins)}';

    protected $description = 'Clear all dummy/sample data (ledgers, roster, claims, logs) while keeping roles, currencies and institutions.';

    /**
     * Tables wiped unconditionally, in FK-safe order (children first).
     * Structural tables (roles, permissions, institutions, currencies) are NOT
     * in this list and are therefore preserved.
     */
    protected array $flush = [
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
        'students',
        'vendors',
        'departments',
        'meal_rate_settings',
        'subsidy_sources',
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

        // Optionally remove the dummy/staff accounts too, keeping Super Admins so
        // the operator is never locked out of the platform.
        if ($this->option('users')) {
            $kept = DB::table('users')
                ->whereIn('id', DB::table('model_has_roles')
                    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                    ->where('roles.name', 'Software Super Admin')
                    ->pluck('model_has_roles.model_id'))
                ->pluck('id');

            $deleted = DB::table('users')->whereNotIn('id', $kept)->delete();
            $this->line("  cleared <comment>users</comment> ({$deleted} removed, Super Admins kept)");
        }

        Schema::enableForeignKeyConstraints();

        $this->newLine();
        $this->info('Dummy data cleared. Roles, permissions, currencies and institutions were preserved.');

        return self::SUCCESS;
    }
}
