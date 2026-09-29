<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Clean, production-shaped seeding.
 *
 * This runs ONLY the structurally-required seeders:
 *   - roles & permissions (the RBAC backbone),
 *   - the currency catalogue,
 *   - the default institution,
 *   - the permanent platform owner (Software Super Admin).
 *
 * It deliberately does NOT create:
 *   - dummy users or a hardcoded admin account,
 *   - sample members / vendors / transactions,
 *   - any of the old "two months of transactions" fixture data.
 *
 * That keeps a fresh install aligned with the generalized multi-institution and
 * member structure, with none of the legacy dorm-specific dummy rows. To wipe
 * existing dummy data from an already-seeded database, run:
 *
 *     php artisan db:clear-dummy
 *
 * --------------------------------------------------------------------------
 * SAFETY CONTRACT - READ BEFORE ADDING A SEEDER HERE
 * --------------------------------------------------------------------------
 * This entry point is run on a LIVE database in several deployment paths
 * (`composer setup`, `php artisan db:seed`, a container boot). Every seeder
 * listed below is therefore bound by two rules:
 *
 *   1. IDEMPOTENT, NON-DESTRUCTIVE. Use `firstOrCreate` / `updateOrCreate`
 *      keyed on a natural identifier (slug, code, email). Never `truncate`,
 *      never `delete`, never `insert` a duplicate, and never `migrate:fresh`.
 *
 *   2. NEVER TOUCH EXISTING CREDENTIALS. A user row is created ONLY when it is
 *      missing; an existing row's `password` / `password_changed_at` is left
 *      exactly as it is. SoftwareSuperAdminSeeder is the reference
 *      implementation - see its class docblock.
 *
 * SAMPLE / DEMO DATA DOES NOT BELONG HERE. It lives in the git-ignored
 * `MockDataSeeder` (`php artisan db:seed --class=MockDataSeeder`), which is
 * excluded from version control precisely so it can never run in production.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            CurrenciesTableSeeder::class,
            // Seeds the default institution + its hub vendor baseline only.
            InstitutionSeeder::class,
            /*
             * THE SUPPORT ASSISTANT'S DOCUMENTATION CORPUS.
             *
             * These are PLATFORM-WIDE answers (institution_id NULL), so they are
             * baseline data rather than sample data - an assistant with an empty
             * corpus escalates every question, including ones whose answers are
             * already documented. Idempotent, so a redeploy updates rather than
             * duplicates.
             */
            AssistantKnowledgeSeeder::class,
            // Runs LAST and is idempotent: guarantees the permanent platform
            // owner (admin@mahfuz.com) always exists, without ever overwriting
            // an existing password or clearing transactional data.
            SoftwareSuperAdminSeeder::class,
        ]);
    }
}
