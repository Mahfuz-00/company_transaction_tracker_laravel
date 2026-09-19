<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Strict multi-tenant isolation, part one: give the LEDGER tables a direct
     * `institution_id`.
     *
     * WHY DENORMALISE
     * ---------------
     * `transactions`, `deposits`, `meal_entries` and `meal_expenses` previously
     * reached their institution only THROUGH a joined student/relation. That made
     * a direct scope impossible: a raw `Transaction::all()` had no column to
     * filter on, so every unlucky query that forgot the join leaked rows across
     * institutions - exactly the reported "deposits from Touch and Solve show in
     * North South University" bug.
     *
     * Adding the column (and a global Eloquent scope over it) lets EVERY query -
     * including relationship queries and aggregates - be filtered with a single
     * indexed predicate that a controller cannot forget.
     *
     * BACKFILL
     * --------
     * Existing rows are filled from their owning student's institution. Rows with
     * no resolvable owner are left NULL, which the scope treats as "legacy /
     * platform-level": visible to the Software Super Admin's global view, but
     * never to a specific institution.
     */
    public function up(): void
    {
        foreach (['transactions', 'deposits', 'meal_entries', 'meal_expenses'] as $table) {
            if (! Schema::hasColumn($table, 'institution_id')) {
                Schema::table($table, function (Blueprint $blueprint) use ($table) {
                    $blueprint->foreignId('institution_id')->nullable()->after('id')
                        ->constrained('institutions')->nullOnDelete();
                    // Index names must be UNIQUE across the database on SQLite, so
                    // each table gets its own suffixed index name.
                    $blueprint->index(['institution_id', 'created_at'], "{$table}_institution_idx");
                });
            }
        }

        // ---- Backfill from the owning member record --------------------------
        // A deposit / meal entry is owned by a student; that student carries the
        // institution. We copy it down so the ledger rows are self-scoping.
        $this->backfillFromStudent('deposits');
        $this->backfillFromStudent('meal_entries');
        $this->backfillFromStudent('transactions');

        // meal_expenses inherit from their linked ledger transaction, which we
        // have just backfilled; fall back to their vendor when that is missing.
        if (Schema::hasColumn('meal_expenses', 'institution_id')) {
            DB::table('meal_expenses')
                ->whereNull('institution_id')
                ->whereNotNull('transaction_id')
                ->update([
                    'institution_id' => DB::raw(
                        '(SELECT institution_id FROM transactions WHERE transactions.id = meal_expenses.transaction_id)'
                    ),
                ]);

            if (Schema::hasColumn('vendors', 'institution_id')) {
                DB::table('meal_expenses')
                    ->whereNull('institution_id')
                    ->whereNotNull('vendor_id')
                    ->update([
                        'institution_id' => DB::raw(
                            '(SELECT institution_id FROM vendors WHERE vendors.id = meal_expenses.vendor_id)'
                        ),
                    ]);
            }
        }
    }

    /**
     * Copy `students.institution_id` onto a ledger table's rows via student_id.
     */
    protected function backfillFromStudent(string $table): void
    {
        if (! Schema::hasColumn($table, 'institution_id') || ! Schema::hasColumn($table, 'student_id')) {
            return;
        }

        DB::table($table)
            ->whereNull('institution_id')
            ->whereNotNull('student_id')
            ->update([
                'institution_id' => DB::raw(
                    '(SELECT institution_id FROM students WHERE students.id = '.$table.'.student_id)'
                ),
            ]);
    }

    public function down(): void
    {
        foreach (['transactions', 'deposits', 'meal_entries', 'meal_expenses'] as $table) {
            if (Schema::hasColumn($table, 'institution_id')) {
                Schema::table($table, function (Blueprint $blueprint) use ($table) {
                    // The named index must be dropped before the column.
                    $blueprint->dropIndex("{$table}_institution_idx");
                    $blueprint->dropConstrainedForeignId('institution_id');
                });
            }
        }
    }
};
