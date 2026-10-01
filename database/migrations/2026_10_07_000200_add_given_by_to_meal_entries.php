<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `given_by` - WHO ACTUALLY LOGGED A MEAL ENTRY.
 *
 * `recorded_by` already tracks the *account* that saved the row, but an
 * institution may have several meal managers, or an admin acting on a manager's
 * behalf. `given_by` records the HUMAN the entry was given by / attributed to -
 * the person whose pass was actually scanned at the counter - so an audit can
 * answer "whose meal count is this really?" independently of "which login typed
 * it in".
 *
 * It is nullable (existing rows have no attribution) and is written by
 * MealEntryController from an explicit form field, defaulting to the recorder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('meal_entries', 'given_by')) {
                // Nullable FK: a free-text attribution is not enough for a join,
                // and nullOnDelete keeps the meal row (the audit fact) if the
                // attributed user is later removed.
                $table->foreignId('given_by')->nullable()->after('recorded_by')
                    ->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('meal_entries', function (Blueprint $table) {
            if (Schema::hasColumn('meal_entries', 'given_by')) {
                $table->dropConstrainedForeignId('given_by');
            }
        });
    }
};
