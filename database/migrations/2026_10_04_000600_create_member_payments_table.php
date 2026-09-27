<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MEMBER-INITIATED MEAL PAYMENTS.
 *
 * Members could previously only have deposits recorded FOR them by a manager.
 * This lets a member start a top-up themselves from their dashboard. The flow is
 * deliberately SAFE:
 *
 *   1. The member creates a payment intent (amount + method).
 *   2. The intent sits in `pending` - it does NOT touch their balance.
 *   3. A manager (or an automated gateway callback) verifies it.
 *   4. ONLY on approval is a real `deposits` row created and the balance moves.
 *
 * That separation is what stops a member crediting their own account. The
 * `reference` is unique so a retried submission cannot double-charge, and
 * `deposit_id` records the deposit that was created on approval (for the audit
 * trail back from the ledger).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Unique, member-facing reference (also the idempotency key).
            $table->string('reference')->unique();

            $table->decimal('amount', 16, 2);
            $table->string('method', 32)->default('cash'); // cash|bkash|nagad|bank|card

            // pending -> approved | rejected | cancelled
            $table->string('status', 24)->default('pending');

            // Free-text the member can add (e.g. a transaction id from a wallet).
            $table->string('payer_reference')->nullable();
            $table->text('note')->nullable();

            // Set on approval: the deposit this became.
            $table->foreignId('deposit_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            $table->timestamps();

            $table->index(['institution_id', 'status']);
            $table->index(['student_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_payments');
    }
};
