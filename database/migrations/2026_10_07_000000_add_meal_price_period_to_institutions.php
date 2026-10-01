<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CONFIGURABLE MEAL PRICE CALCULATION PERIOD.
 *
 * `meal_price_period` lets an institution decide the window over which the
 * per-meal price is derived and reported:
 *
 *   daily   - the rate is recomputed for each single day
 *   weekly  - the rate is derived over a 7-day window
 *   monthly - the rate is derived over a calendar month (the historical default)
 *
 * The value is a tenant-level SETTING (not per-user), so every role in the
 * workspace sees the same analytics, tables and invoices. Default 'monthly'
 * preserves the existing behaviour for every institution already in the DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            if (! Schema::hasColumn('institutions', 'meal_price_period')) {
                $table->string('meal_price_period', 20)
                    ->default('monthly')
                    ->after('subsidy_mode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            if (Schema::hasColumn('institutions', 'meal_price_period')) {
                $table->dropColumn('meal_price_period');
            }
        });
    }
};
