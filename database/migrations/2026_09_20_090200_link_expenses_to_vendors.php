<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expenses now link directly to a vendor, so recurring shopping and
     * services can be attributed to the right supplier from the expense form
     * (backed by the vendor module).
     */
    public function up(): void
    {
        Schema::table('meal_expenses', function (Blueprint $table) {
            if (! Schema::hasColumn('meal_expenses', 'vendor_id')) {
                $table->foreignId('vendor_id')->nullable()->after('category')
                    ->constrained('vendors')->nullOnDelete();
            }

            if (! Schema::hasColumn('meal_expenses', 'payment_status')) {
                // unpaid | paid | partial - drives a vendor's outstanding balance.
                $table->string('payment_status', 20)->default('paid')->after('vendor_id');
            }

            if (! Schema::hasColumn('meal_expenses', 'amount')) {
                // Stored directly so an expense is readable without a join.
                $table->decimal('amount', 14, 2)->nullable()->after('payment_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('meal_expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_id');
            $table->dropColumn(['payment_status', 'amount']);
        });
    }
};
