<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MULTI-CURRENCY + FX RATE SNAPSHOTS.
 *
 * Every institution already carries a `currency_code`. What was missing is the
 * ability for the Software Super Admin to report revenue ACROSS institutions that
 * bill in different currencies - you cannot sum BDT and USD without a rate.
 *
 * `fx_rates` stores a SNAPSHOT per (base, quote) pair on a given day. Snapshots,
 * not a single live rate, because:
 *   - a figure reported last month must not change when today's rate moves,
 *   - an audit trail of "what rate did we use" is required for finance.
 *
 * Lookup rule: the most recent snapshot on or before the requested date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_rates', function (Blueprint $table) {
            $table->id();

            // ISO-4217 codes, upper-cased (e.g. BDT, USD, EUR).
            $table->string('base_code', 10);
            $table->string('quote_code', 10);

            // How many units of `quote` one unit of `base` buys.
            $table->decimal('rate', 20, 10);

            // The day this rate is considered valid from.
            $table->date('effective_on');

            // Where the rate came from: 'manual' (SSA), 'ecb', 'openexchange', ...
            $table->string('source', 32)->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One snapshot per pair per day, and the index that makes the
            // "latest on or before" lookup fast.
            $table->unique(['base_code', 'quote_code', 'effective_on']);
            $table->index(['base_code', 'quote_code', 'effective_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rates');
    }
};
