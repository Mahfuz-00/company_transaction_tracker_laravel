<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn(config('permission.table_names.permissions', 'permissions'), 'module')) {
            Schema::table(config('permission.table_names.permissions', 'permissions'), function (Blueprint $table) {
                $table->string('module')->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn(config('permission.table_names.permissions', 'permissions'), 'module')) {
            Schema::table(config('permission.table_names.permissions', 'permissions'), function (Blueprint $table) {
                $table->dropColumn('module');
            });
        }
    }
};
