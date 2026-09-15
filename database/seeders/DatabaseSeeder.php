<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Database\Seeders\TwoMonthsTransactionsSeeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Call roles & permissions seeder first
        $this->call(RolesAndPermissionsSeeder::class);

        $adminRole = Role::firstOrCreate(['name' => 'Super Admin']);

        $user = User::firstOrCreate([
            'email' => 'mahfuz@example.com',
        ], [
            'name' => 'Md. Abdullah al Mahfuz',
            'password' => bcrypt('password123'),
        ]);

        if (! $user->hasRole($adminRole->name)) {
            $user->assignRole($adminRole->name);
        }

        // two months of sample transactions for the seeded user
        $this->call(TwoMonthsTransactionsSeeder::class);
    }
}