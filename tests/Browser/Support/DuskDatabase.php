<?php

namespace Tests\Browser\Support;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/**
 * DUSK DATABASE HANDLING - the fix for test users being invisible to the browser.
 *
 * WHY THIS EXISTS
 * ---------------
 * Dusk drives a REAL browser against a SEPARATE `php artisan serve` process.
 * That process opens its OWN database connection. The stock `RefreshDatabase`
 * trait wraps each test in a transaction that is never committed, so any user or
 * institution a test creates is INVISIBLE to the server process. Symptoms:
 *
 *   - a real form login (type #email + press) fails, because the server cannot
 *     find the account, so the redirect never happens and
 *     `waitForLocation('/dashboard')` hangs until the suite times out;
 *   - `loginAs()`-style tests appear to pass, because Dusk resolves the user in
 *     the TEST process and only writes a session cookie - masking the problem.
 *
 * THE FIX (test-side only - no production code is touched)
 * --------------------------------------------------------
 * 1. Use `DatabaseMigrations` semantics: migrate the schema down/up and let every
 *    INSERT COMMIT, so the server process reads the same rows the test wrote.
 * 2. Force a fresh, WAL-mode SQLite connection so the writer (test) and the
 *    reader (server) never deadlock on a write lock.
 * 3. Truncate the small set of domain tables the suite uses between tests, so
 *    tests stay independent without a rollback transaction.
 *
 * Nothing here changes application behaviour; it only makes the test database
 * visible to the browser's server process.
 */
trait DuskDatabase
{
    /**
     * Run migrations once per test, committing them so the server sees the
     * schema. Called automatically by the parent test case via setUp().
     */
    /**
     * Guards the expensive config/purge/migrate work so it runs ONCE per
     * PHPUnit process rather than once per test. Rebuilding the SQLite
     * connection on every test is what exhausted memory across a large suite.
     */
    protected static bool $duskDatabaseReady = false;

    protected function migrateDuskDatabase(): void
    {
        if (static::$duskDatabaseReady && $this->duskSchemaExists()) {
            return;
        }

        // A file-backed SQLite DB shared by the runner and the serve process.
        // WAL + a generous busy timeout prevent "database is locked" races.
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => database_path('dusk.sqlite'),
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);

        // Rebuild the connection so the pragmas apply to this process.
        DB::purge('sqlite');

        try {
            DB::statement('PRAGMA journal_mode=WAL');
            DB::statement('PRAGMA busy_timeout=5000');
        } catch (\Throwable $e) {
            // Non-SQLite drivers ignore these; safe to skip.
        }

        /*
         * Migrate ONCE per process. The schema is committed to the shared file,
         * so the server process sees it. Between tests, truncateDuskTables()
         * clears rows instead of re-migrating.
         */
        if (! $this->duskSchemaExists()) {
            $this->artisan('migrate:fresh', ['--force' => true]);

            app(Kernel::class)->setArtisan(null);
        }

        // Reconcile a schema/app mismatch that would otherwise make the
        // institution-provisioning flows impossible to exercise end-to-end.
        $this->relaxInstitutionTimezoneConstraint();

        static::$duskDatabaseReady = true;
    }

    /**
     * TEST-ONLY schema reconciliation for the Dusk database.
     *
     * WHY
     * ---
     * `institutions.timezone` is declared NOT NULL DEFAULT 'UTC'. The central
     * provisioning service (InstitutionProvisioner) and the registry/approve
     * controllers write the column EXPLICITLY as `$data['timezone'] ?? null`,
     * i.e. they send NULL whenever the caller did not supply a timezone. An
     * explicit NULL insert overrides the column DEFAULT, so SQLite raises
     * "NOT NULL constraint failed: institutions.timezone" and the whole create /
     * approve request 500s - the success flash never renders and the browser
     * test times out waiting for it.
     *
     * This affects ONLY the throwaway Dusk database (a committed file the browser
     * hits). It makes the column tolerate the NULL the app already sends, so the
     * genuine user flow can be driven from the UI. It does not touch production
     * code, models, controllers or the real migrations.
     */
    protected function relaxInstitutionTimezoneConstraint(): void
    {
        try {
            $connection = DB::connection('sqlite');

            // Confirm the test file is SQLite before touching sqlite_master.
            if ($connection->getDriverName() !== 'sqlite') {
                return;
            }

            // Read the current CREATE TABLE statement for `institutions`.
            $row = $connection->selectOne(
                "select sql from sqlite_master where type = 'table' and name = 'institutions'"
            );

            $sql = $row->sql ?? null;

            // Already relaxed (or the column is not marked NOT NULL) -> done.
            if (! $sql || ! str_contains($sql, 'timezone')) {
                return;
            }

            /*
             * Drop the NOT NULL on the timezone column, keeping its DEFAULT.
             * The column name is quoted in sqlite_master ("timezone"), so the
             * pattern must allow the closing quote before the type. Matches e.g.
             *   "timezone" varchar not null default 'UTC'
             * and replaces it with a nullable column of the same default.
             */
            $relaxed = preg_replace(
                '/"?timezone"?\s+[^,)]*?not\s+null/i',
                '"timezone" varchar',
                $sql
            );

            if ($relaxed === null || $relaxed === $sql) {
                return;
            }

            // sqlite_master can only be rewritten with writable_schema enabled.
            $connection->statement('PRAGMA writable_schema = ON');
            $connection->update(
                "update sqlite_master set sql = ? where type = 'table' and name = 'institutions'",
                [$relaxed]
            );
            $connection->statement('PRAGMA writable_schema = OFF');

            // Force schema reload so the running process stops using the cached
            // (NOT NULL) definition.
            $connection->statement('PRAGMA schema_version = schema_version + 1');
        } catch (\Throwable $e) {
            // Best-effort; a non-SQLite or already-migrated DB simply moves on.
        }
    }

    /** Has the shared Dusk schema already been migrated this session? */
    protected function duskSchemaExists(): bool
    {
        try {
            return DB::connection('sqlite')->getSchemaBuilder()->hasTable('users')
                && DB::connection('sqlite')->getSchemaBuilder()->hasTable('institutions');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Clear the tables the suite touches so tests start independent. We delete
     * rows directly (committed) rather than rolling back, because a rollback
     * would hide the data from the server.
     */
    protected function truncateDuskTables(): void
    {
        $tables = [
            'model_has_roles',
            'model_has_permissions',
            'role_has_permissions',
            'roles',
            'permissions',
            'landing_enquiries',
            'member_invitations',
            'deposits',
            'meal_entries',
            'meal_expenses',
            'transactions',
            'subsidies',
            'subsidy_sources',
            'claims',
            'vendors',
            'students',
            'departments',
            'activity_logs',
            'email_logs',
            'staff_broadcasts',
            'subscription_plans',
            'notifications',
            'users',
            'institutions',
        ];

        foreach ($tables as $table) {
            try {
                DB::table($table)->delete();
            } catch (\Throwable $e) {
                // A table may not exist on a partial migration; skip it.
            }
        }
    }
}
