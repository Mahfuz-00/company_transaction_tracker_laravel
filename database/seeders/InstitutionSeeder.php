<?php

namespace Database\Seeders;

use App\Models\Institution;
use App\Models\Vendor;
use Illuminate\Database\Seeder;

class InstitutionSeeder extends Seeder
{
    public function run(): void
    {
        // Keep the existing dorm as the starting institution, but seed the
        // columns so the app has something coherent to read on first boot.
        $institution = Institution::firstOrCreate(
            ['slug' => 'main-campus-dorm'],
            [
                'name' => 'Main Campus Dorm',
                'type' => 'university_dorm',
                'timezone' => 'Asia/Dhaka',
                'is_active' => true,
                'contact_email' => null,
                'contact_phone' => null,
            ]
        );

        // A few realistic suppliers so the vendor module is usable immediately.
        $vendors = [
            ['name' => 'Rahim General Store', 'category' => 'groceries', 'contact_person' => 'Rahim Uddin', 'phone' => '+8801711000001'],
            ['name' => 'Karwan Bazar Vegetables', 'category' => 'vegetables', 'contact_person' => 'Abdul Karim', 'phone' => '+8801711000002'],
            ['name' => 'Fresh Fish Corner', 'category' => 'meat_fish', 'contact_person' => 'Jamal Hossain', 'phone' => '+8801711000003'],
            ['name' => 'City Gas Supply', 'category' => 'cooking_gas', 'contact_person' => null, 'phone' => '+8801711000004'],
        ];

        foreach ($vendors as $data) {
            Vendor::firstOrCreate(
                ['name' => $data['name']],
                $data + ['institution_id' => $institution->id, 'status' => 'active']
            );
        }
    }
}
