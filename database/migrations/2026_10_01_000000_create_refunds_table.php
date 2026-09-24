<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Member balance REFUNDS - money paid back OUT of a member's meal wallet.
 *
 * WHY A DEDICATED TABLE (not a negative deposit)
 * ----------------------------------------------
 * A deposit is the CREDIT side of the ledger. A refund is the opposite flow:
 * the member stops meals or withdraws their remaining balance, so the pool pays
 * money back. Modelling it as a first-class row (rather than a negative deposit)
 * keeps the two directions separable in reports, gives us a recorded reason, and
 * mirrors the deposit table's audit shape exactly.
 *
 * REVERSAL, NOT DELETION - same rule as deposits/expenses: a refund is never
 * deleted. Reversing stamps `reversed_at` / `reversed_by` and links a
 * compensating cash-in, so a mistaken refund is corrected without erasing the
 * trail. Only non-reversed refunds reduce a member's balance.
 *
 * TENANCY: carries `institution_id` directly (like the other ledger tables) so
 * the BelongsToInstitution global scope can filter it without a join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();

            $table->foreignId('institution_id')->nullable()
                ->constrained('institutions')->nullOnDelete();

            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();

            $table->decimal('amount', 14, 2);

            // withdrawal | stopped_meals | settlement | other
            $table->string('reason')->default('withdrawal');

            $table->string('payment_method')->nullable();

            $table->foreignId('recorded_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()
                ->constrained('transactions')->nullOnDelete();

            $table->text('notes')->nullable();

            // Reversal trail.
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('reversal_transaction_id')->nullable()
                ->constrained('transactions')->nullOnDelete();

            $table->timestamps();

            // Per-table index name: index names must be unique across the schema.
            $table->index(['institution_id', 'created_at'], 'refunds_institution_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
