<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives transactions dorm-specific meaning.
 *
 * Before this, a transaction was a generic income/expense row owned by the
 * user who typed it. Now:
 *   - type = 'in'  -> the money came from a student (student_id set)
 *   - type = 'out' -> the money went to a vendor/payee for supplies
 *
 * All columns are nullable so existing rows remain valid and the original
 * generic transaction tracker keeps working.
 */

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'student_id')) {
                // Who paid this in. Null = generic transaction, not a deposit.
                $table->foreignId('student_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('students')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('transactions', 'payee')) {
                // Who was paid for an expense (vendor / shop / person).
                $table->string('payee')->nullable()->after('by_whom');
            }

            if (! Schema::hasColumn('transactions', 'reason')) {
                // Why the money moved - richer than `item`.
                $table->text('reason')->nullable()->after('payee');
            }

            if (! Schema::hasColumn('transactions', 'source')) {
                // How the row was created: manual form vs a meal module.
                $table->string('source')->default('manual')->after('reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'student_id')) {
                $table->dropConstrainedForeignId('student_id');
            }

            foreach (['payee', 'reason', 'source'] as $column) {
                if (Schema::hasColumn('transactions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
