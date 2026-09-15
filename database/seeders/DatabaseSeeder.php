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
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $userRole = Role::firstOrCreate(['name' => 'user']);

        $user = User::firstOrCreate([
            'email' => 'mahfuz@example.com',
        ], [
            'name' => 'Md. Abdullah al Mahfuz',
            'password' => bcrypt('password123'),
        ]);

        if (! $user->hasRole($userRole->name)) {
            $user->assignRole($userRole);
        }

        // two months of sample transactions for the seeded user
        $this->call(TwoMonthsTransactionsSeeder::class);
    }
}