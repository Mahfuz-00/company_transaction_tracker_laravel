<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE INSTITUTION'S PAID-UP DATE.
 *
 * `subscription_ends_at` records how far an institution's subscription is paid up.
 * Approving a subscription payment extends THIS column, so "is this workspace paid
 * up?" is a single, cheap read rather than a re-derivation from the payment history
 * on every request.
 *
 * WHY A SEPARATE MIGRATION
 * ------------------------
 * The column was originally added inside create_subscription_payments_table, which
 * had ALREADY been recorded as run on existing deployments - so the extra
 * Schema::table() block never executed there. The Dusk database re-migrates from
 * scratch and therefore HAD the column, which is exactly the kind of environment
 * divergence that hides a bug until production. A standalone, idempotent migration
 * guarantees the column exists on every database, whatever its history.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('institutions', 'subscription_ends_at')) {
            return;
        }

        Schema::table('institutions', function (Blueprint $table) {
            $table->date('subscription_ends_at')->nullable()->after('trial_ends_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('institutions', 'subscription_ends_at')) {
            return;
        }

        Schema::table('institutions', function (Blueprint $table) {
            $table->dropColumn('subscription_ends_at');
        });
    }
};
