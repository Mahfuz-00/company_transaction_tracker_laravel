<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CurrenciesTableSeeder extends Seeder
{
    /**
     * Seed the currencies table with world currencies dataset.
     */
    public function run(): void
    {
        $currencies = [
            ['name' => 'Bangladeshi Taka', 'symbol' => '৳', 'code' => 'BDT'],
            ['name' => 'US Dollar', 'symbol' => '$', 'code' => 'USD'],
            ['name' => 'Euro', 'symbol' => '€', 'code' => 'EUR'],
            ['name' => 'Japanese Yen', 'symbol' => '¥', 'code' => 'JPY'],
            ['name' => 'British Pound', 'symbol' => '£', 'code' => 'GBP'],
            ['name' => 'Australian Dollar', 'symbol' => 'A$', 'code' => 'AUD'],
            ['name' => 'Canadian Dollar', 'symbol' => 'CA$', 'code' => 'CAD'],
            ['name' => 'Swiss Franc', 'symbol' => 'CHF', 'code' => 'CHF'],
            ['name' => 'Chinese Yuan', 'symbol' => 'CN¥', 'code' => 'CNY'],
            ['name' => 'Hong Kong Dollar', 'symbol' => 'HK$', 'code' => 'HKD'],
            ['name' => 'New Zealand Dollar', 'symbol' => 'NZ$', 'code' => 'NZD'],
            ['name' => 'Swedish Krona', 'symbol' => 'SEK', 'code' => 'SEK'],
            ['name' => 'South Korean Won', 'symbol' => '₩', 'code' => 'KRW'],
            ['name' => 'Singapore Dollar', 'symbol' => 'SGD', 'code' => 'SGD'],
            ['name' => 'Norwegian Krone', 'symbol' => 'NOK', 'code' => 'NOK'],
            ['name' => 'Mexican Peso', 'symbol' => 'MX$', 'code' => 'MXN'],
            ['name' => 'Indian Rupee', 'symbol' => '₹', 'code' => 'INR'],
            ['name' => 'Russian Ruble', 'symbol' => 'RUB', 'code' => 'RUB'],
            ['name' => 'South African Rand', 'symbol' => 'ZAR', 'code' => 'ZAR'],
            ['name' => 'Turkish Lira', 'symbol' => '₺', 'code' => 'TRY'],
            ['name' => 'Brazilian Real', 'symbol' => 'R$', 'code' => 'BRL'],
            ['name' => 'New Taiwan Dollar', 'symbol' => 'NT$', 'code' => 'TWD'],
            ['name' => 'Danish Krone', 'symbol' => 'DKK', 'code' => 'DKK'],
            ['name' => 'Polish Zloty', 'symbol' => 'PLN', 'code' => 'PLN'],
            ['name' => 'Thai Baht', 'symbol' => '฿', 'code' => 'THB'],
            ['name' => 'Indonesian Rupiah', 'symbol' => 'IDR', 'code' => 'IDR'],
            ['name' => 'Hungarian Forint', 'symbol' => 'HUF', 'code' => 'HUF'],
            ['name' => 'Czech Koruna', 'symbol' => 'CZK', 'code' => 'CZK'],
            ['name' => 'Israeli New Shekel', 'symbol' => '₪', 'code' => 'ILS'],
            ['name' => 'Chilean Peso', 'symbol' => 'CLP', 'code' => 'CLP'],
            ['name' => 'Philippine Peso', 'symbol' => 'PHP', 'code' => 'PHP'],
            ['name' => 'UAE Dirham', 'symbol' => 'AED', 'code' => 'AED'],
            ['name' => 'Saudi Riyal', 'symbol' => 'SAR', 'code' => 'SAR'],
            ['name' => 'Malaysian Ringgit', 'symbol' => 'MYR', 'code' => 'MYR'],
        ];

        $now = now();

        foreach ($currencies as $currency) {
            DB::table('currencies')->updateOrInsert(
                ['code' => $currency['code']],
                [
                    'name'       => $currency['name'],
                    'symbol'     => $currency['symbol'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }
}
