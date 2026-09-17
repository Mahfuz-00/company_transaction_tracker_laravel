<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Clean, production-shaped seeding.
 *
 * This runs ONLY the structurally-required seeders:
 *   - roles & permissions (the RBAC backbone),
 *   - the currency catalogue,
 *   - the default institution.
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
        ]);
    }
}