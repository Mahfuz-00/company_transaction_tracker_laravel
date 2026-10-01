<?php

namespace Tests\Browser\Support;

use Tests\DuskTestCase;

/**
 * THE DATABASE PROTECTION GUARD.
 *
 * This is the highest-value test in the suite: it proves that a misconfigured
 * environment CANNOT destroy the developer's data.
 *
 * WHY IT EXISTS
 * -------------
 * The Dusk suite truncates every table between tests. That is necessary — tests
 * must start from a known state — but it is indiscriminate: it deletes rows in
 * whichever database it is pointed at.
 *
 * This repository has already lost data to exactly that. `.env` once pointed at
 * the test database, so every `php artisan dusk` silently erased the developer's
 * working data. Symptoms looked like application bugs (accounts "losing" their
 * password, members vanishing overnight) and the cause was a config file.
 *
 * `assertDuskDatabaseIsDisposable()` is the enforcement point. These tests make
 * sure it stays strict: an allow-list of real test filenames, plus a hard refusal
 * of the development database by name.
 *
 * IF A TEST HERE FAILS, DO NOT WEAKEN THE GUARD. Fix the environment file.
 */
class DatabaseProtectionTest extends DuskTestCase
{
    use DuskDatabase;

    /**
     * The configured test database must be a KNOWN disposable file.
     *
     * This asserts the positive case: the suite is pointed somewhere it is
     * genuinely allowed to truncate.
     */
    public function test_the_suite_runs_against_a_dedicated_test_database(): void
    {
        $database = str_replace('\\', '/', (string) config('database.connections.sqlite.database'));
        $filename = basename($database);

        $this->assertContains(
            $filename,
            ['testing.sqlite', ':memory:'],
            'The suite must run against a dedicated test database, not: '.$database
        );

        // And it must NOT be the application's own database file.
        $this->assertStringNotContainsString(
            'database/database.sqlite',
            $database,
            'CRITICAL: the suite is pointed at the DEVELOPMENT database.'
        );
    }

    /**
     * The guard REFUSES a non-disposable path.
     *
     * The negative case, and the one that actually protects the developer: if the
     * configured database is anything other than a known test file, the guard must
     * throw BEFORE any migration or truncation runs.
     *
     * The path is swapped only in the in-memory config for the duration of this
     * assertion, so no real file is touched.
     */
    public function test_the_guard_refuses_a_non_test_database(): void
    {
        $original = config('database.connections.sqlite.database');

        try {
            // Pretend a developer (or an agent) pointed the suite at real data.
            config(['database.connections.sqlite.database' => database_path('database.sqlite')]);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/REFUSING TO RUN/');

            $this->assertDuskDatabaseIsDisposable();
        } finally {
            // Restore immediately, so a failure here cannot leak into another test.
            config(['database.connections.sqlite.database' => $original]);
        }
    }

    /**
     * The guard refuses an ARBITRARY filename, even one that "looks" like a test
     * database. This is what makes it an allow-list rather than a suffix check.
     */
    public function test_the_guard_is_an_allow_list_not_a_suffix_check(): void
    {
        $original = config('database.connections.sqlite.database');

        try {
            config(['database.connections.sqlite.database' => database_path('my-testing.sqlite')]);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/REFUSING TO RUN/');

            $this->assertDuskDatabaseIsDisposable();
        } finally {
            config(['database.connections.sqlite.database' => $original]);
        }
    }

    /**
     * The legacy `dusk.sqlite` filename is ALSO refused.
     *
     * It is the specific file that caused the original incident: `.env` shared it
     * with the test suite, so the dev database and the test database were one and
     * the same. Re-allowing it would re-open that exact hole.
     */
    public function test_the_legacy_shared_dusk_filename_is_refused(): void
    {
        $original = config('database.connections.sqlite.database');

        try {
            config(['database.connections.sqlite.database' => database_path('dusk.sqlite')]);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/REFUSING TO RUN/');

            $this->assertDuskDatabaseIsDisposable();
        } finally {
            config(['database.connections.sqlite.database' => $original]);
        }
    }

    /** The development database file still exists and is untouched by the suite. */
    public function test_the_development_database_is_never_touched(): void
    {
        $devDatabase = database_path('database.sqlite');

        // A fresh clone may not have one yet; if it does, it must be readable and
        // must not be the database this suite is operating on.
        if (! file_exists($devDatabase)) {
            $this->markTestSkipped('No development database on this machine.');
        }

        $this->assertIsReadable($devDatabase);

        $this->assertNotSame(
            realpath($devDatabase),
            realpath((string) config('database.connections.sqlite.database')),
            'The test connection must never resolve to the development database file.'
        );
    }
}
