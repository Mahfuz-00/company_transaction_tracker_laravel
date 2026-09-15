<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // add new columns with sensible defaults so existing rows get non-null values
            $table->string('payment_method')->default('Cash')->after('amount');
            $table->string('by_whom')->default('Unknown')->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'by_whom']);
        });
    }
};
