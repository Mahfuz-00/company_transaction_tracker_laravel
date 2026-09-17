<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Member claims & disputes.
 *
 * A single table backs both halves of the claim workflow, distinguished by
 * `kind`:
 *
 *   - 'dispute' : a member says a deposit or meal entry is missing / wrong.
 *                 Approving it creates the missing record (or adjustment).
 *   - 'expense' : a member bought something for the institution out of pocket
 *                 ("Buy Something"). Approving it records a reimbursable
 *                 expense and credits the member's balance.
 *
 * A claim is a request, not a ledger movement: it only touches money once a
 * manager approves it. Every state change is timestamped and attributed so the
 * audit trail is complete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claims', function (Blueprint $table) {
            $table->id();

            // Who and where. institution_id is denormalised so a manager's list
            // can be scoped with a single indexed predicate.
            $table->foreignId('institution_id')->nullable()->constrained('institutions')->nullOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();

            // dispute | expense
            $table->string('kind', 20)->default('dispute');

            // For a dispute: deposit | meal. For an expense: a free category.
            $table->string('subject', 40)->nullable();

            // Money the claim is about (expected deposit, or amount spent).
            $table->decimal('amount', 14, 2)->nullable();

            // For a meal dispute: which day and which meals were missed.
            $table->date('entry_date')->nullable();
            $table->unsignedTinyInteger('breakfast')->nullable();
            $table->unsignedTinyInteger('lunch')->nullable();
            $table->unsignedTinyInteger('dinner')->nullable();

            // What the member says / where they bought it.
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('claim_date')->nullable();
            $table->string('payment_method')->nullable();

            // pending | approved | rejected
            $table->string('status', 20)->default('pending');

            // Review decision.
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            // The record produced when the claim was approved, so the decision is
            // traceable back to the ledger rows it created.
            $table->foreignId('result_deposit_id')->nullable()->constrained('deposits')->nullOnDelete();
            $table->foreignId('result_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();

            $table->timestamps();

            $table->index(['institution_id', 'status']);
            $table->index(['student_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claims');
    }
};
