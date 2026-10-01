<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            if (! Schema::hasColumn('claims', 'adjust_balance')) {
                // If true, approved wrongful count/expense claim counts directly toward member's money-in balance
                $table->boolean('adjust_balance')->default(false)->after('amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            if (Schema::hasColumn('claims', 'adjust_balance')) {
                $table->dropColumn('adjust_balance');
            }
        });
    }
};
