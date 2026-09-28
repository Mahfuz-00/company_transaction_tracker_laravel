<?php

namespace Database\Seeders;

use App\Models\Institution;
use Illuminate\Database\Seeder;

class InstitutionSeeder extends Seeder
{
    /**
     * The natural key of the baseline workspace. `firstOrCreate` is keyed on
     * this slug, so re-running the seeder is a no-op on an existing install and
     * NEVER overwrites an operator's edited name, subtitle, theme or logo.
     */
    public const DEFAULT_SLUG = 'default-institution';

    public function run(): void
    {
        /*
         * Seed a SINGLE default workspace so the app has something coherent to
         * read on first boot. It is intentionally generic (not dorm-specific),
         * matching the generalized multi-institution model.
         *
         * IDEMPOTENT BY SLUG: the second argument to firstOrCreate is applied
         * ONLY when no row with that slug exists. An operator who has renamed
         * the workspace or uploaded a logo keeps their changes on every
         * subsequent `db:seed` - which is what makes this safe to run against a
         * live database.
         *
         * No dummy vendors, members, or transactions are created here - the
         * operator starts with a clean workspace and adds real data. The hub
         * vendor is created lazily by the institution on first vendor access.
         * Sample data lives in the git-ignored MockDataSeeder instead.
         */
        Institution::firstOrCreate(
            ['slug' => self::DEFAULT_SLUG],
            [
                'name' => 'Default Institution',
                'subtitle' => 'Shared meals, tracked',
                'type' => 'general_mess',
                'timezone' => 'UTC',
                'is_active' => true,
                'contact_email' => null,
                'contact_phone' => null,
            ]
        );
    }
}
