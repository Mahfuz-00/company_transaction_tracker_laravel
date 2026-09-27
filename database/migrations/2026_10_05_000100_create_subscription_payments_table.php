<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INSTITUTION SUBSCRIPTION PAYMENTS.
 *
 * Institutions can now pay their platform subscription from their own dashboard,
 * instead of the SSA recording an offline arrangement.
 *
 * THE SAFETY MODEL mirrors member payments (MemberPayment), for the same reason:
 *   - an Institution Admin SUBMITS a payment intent -> status `pending`
 *   - it does NOT change the subscription's status
 *   - the SOFTWARE SUPER ADMIN verifies it -> status `approved`
 *   - only then is a paid period recorded on the institution
 *
 * The SSA is the counterparty here (they own the billing), so verification is
 * theirs alone. `reference` is unique so a retried submission cannot double-charge,
 * and `period_months` records what the money actually bought.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();

            // Unique, human-facing reference AND the idempotency key.
            $table->string('reference')->unique();

            // What is being paid for.
            $table->string('plan_name')->nullable();
            $table->decimal('amount', 16, 2);
            $table->string('currency_code', 10)->default('USD');
            // How many months of service this payment covers.
            $table->unsignedSmallInteger('period_months')->default(1);

            $table->string('method', 32)->default('bank'); // bank|card|bkash|nagad|wire

            // pending -> approved | rejected | cancelled
            $table->string('status', 24)->default('pending');

            $table->date('paid_on')->nullable();
            // The transaction id from the payer's side (bank ref, gateway id).
            $table->string('payer_reference')->nullable();
            $table->text('note')->nullable();

            // Set on approval: the service period this payment bought.
            $table->date('covers_from')->nullable();
            $table->date('covers_to')->nullable();

            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            $table->timestamps();

            $table->index(['institution_id', 'status']);
        });

        /*
         * NOTE: the institution's paid-up date (`institutions.subscription_ends_at`)
         * is added by its OWN migration (2026_10_06_000000), NOT here. Adding it in
         * this file's Schema::table() block only worked on a database that had never
         * run this migration - on an existing deployment the extra column was
         * silently skipped. A standalone migration guarantees it everywhere.
         */
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
