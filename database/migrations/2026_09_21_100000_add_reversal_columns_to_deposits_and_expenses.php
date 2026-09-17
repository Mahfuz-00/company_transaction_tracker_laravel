<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reversal + edit tracking for financial rows.
 *
 * Deposits and expenses are financial records: rather than deleting them (which
 * would orphan the linked ledger transaction), a reversal keeps the original row
 * for the audit trail and flags it, while posting a matching counter-transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('transaction_id');
            $table->foreignId('reversed_by')->nullable()->after('reversed_at')
                ->constrained('users')->nullOnDelete();
            // The cash-out posted to neutralise this deposit's cash-in.
            $table->foreignId('reversal_transaction_id')->nullable()->after('reversed_by')
                ->constrained('transactions')->nullOnDelete();
        });

        Schema::table('meal_expenses', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('recorded_by');
            $table->foreignId('reversed_by')->nullable()->after('reversed_at')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('reversal_transaction_id')->nullable()->after('reversed_by')
                ->constrained('transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversal_transaction_id');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn('reversed_at');
        });

        Schema::table('meal_expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversal_transaction_id');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn('reversed_at');
        });
    }
};
