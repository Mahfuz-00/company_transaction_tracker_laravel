<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CurrenciesTableSeeder extends Seeder
{
    public function run()
    {
        $data = [
            ['name' => 'Bangladeshi Taka', 'symbol' => '৳', 'code' => 'BDT'],
            ['name' => 'US Dollar', 'symbol' => '$', 'code' => 'USD'],
            ['name' => 'Euro', 'symbol' => '€', 'code' => 'EUR'],
            ['name' => 'British Pound', 'symbol' => '£', 'code' => 'GBP'],
            ['name' => 'Indian Rupee', 'symbol' => '₹', 'code' => 'INR'],
        ];

        foreach ($data as $row) {
            DB::table('currencies')->updateOrInsert(['code' => $row['code']], $row + ['created_at' => now(), 'updated_at' => now()]);
        }
    }
}
