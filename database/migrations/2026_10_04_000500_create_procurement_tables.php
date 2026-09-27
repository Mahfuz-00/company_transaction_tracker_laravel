<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VENDOR INVOICES & PURCHASE ORDER WORKFLOW (3-WAY MATCH).
 *
 * The vendor module recorded suppliers but not what was BOUGHT from them. This
 * adds the standard procure-to-pay chain:
 *
 *   purchase_orders      : what we ordered  (line items, agreed prices)
 *   goods_receipts       : what actually arrived (quantities received)
 *   vendor_invoices      : what the vendor billed (quantities + prices charged)
 *
 * The THREE-WAY MATCH compares all three: an invoice line is only clearable when
 * ordered ≈ received ≈ billed, within tolerance. Any mismatch becomes a
 * `match_status` of 'variance' with the reason recorded, so a human can decide.
 *
 * APPROVALS: a PO moves draft -> submitted -> approved -> ordered -> received ->
 * closed. `approved_by` / `approved_at` capture who signed it off, which is the
 * control an institution actually needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reference')->unique();   // human-facing PO number
            $table->string('status', 24)->default('draft'); // draft|submitted|approved|ordered|received|closed|cancelled

            $table->date('ordered_on')->nullable();
            $table->date('expected_on')->nullable();

            $table->decimal('subtotal', 16, 2)->default(0);
            $table->decimal('tax', 16, 2)->default(0);
            $table->decimal('total', 16, 2)->default(0);

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            $table->index(['institution_id', 'status']);
            $table->index('vendor_id');
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();

            $table->string('description');
            $table->string('unit', 24)->default('unit');
            $table->decimal('quantity', 14, 3)->default(0);
            $table->decimal('unit_price', 14, 4)->default(0);
            $table->decimal('line_total', 16, 2)->default(0);

            $table->timestamps();
        });

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('reference')->nullable();
            $table->date('received_on')->nullable();
            // JSON: [{ item_id, quantity }] - what actually turned up.
            $table->json('lines')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('purchase_order_id');
        });

        Schema::create('vendor_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reference');              // the vendor's invoice number
            $table->string('status', 24)->default('pending'); // pending|matched|variance|approved|paid|disputed

            $table->date('invoiced_on')->nullable();
            $table->date('due_on')->nullable();

            $table->decimal('subtotal', 16, 2)->default(0);
            $table->decimal('tax', 16, 2)->default(0);
            $table->decimal('total', 16, 2)->default(0);

            // JSON: [{ item_id, quantity, unit_price }] - what was billed.
            $table->json('lines')->nullable();

            // The outcome of the 3-way match, kept so the UI can explain itself.
            $table->string('match_status', 24)->default('unmatched');
            $table->json('match_report')->nullable();
            $table->timestamp('matched_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['institution_id', 'status']);
            $table->index(['purchase_order_id', 'match_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_invoices');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
