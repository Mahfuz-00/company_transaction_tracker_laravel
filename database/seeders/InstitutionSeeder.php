<?php

namespace Database\Seeders;

use App\Models\Institution;
use Illuminate\Database\Seeder;

class InstitutionSeeder extends Seeder
{
    public function run(): void
    {
        /*
         * Seed a SINGLE default workspace so the app has something coherent to
         * read on first boot. It is intentionally generic (not dorm-specific),
         * matching the generalized multi-institution model.
         *
         * No dummy vendors, members, or transactions are created here - the
         * operator starts with a clean workspace and adds real data. The hub
         * vendor is created lazily by the institution on first vendor access.
         */
        Institution::firstOrCreate(
            ['slug' => 'default-institution'],
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
