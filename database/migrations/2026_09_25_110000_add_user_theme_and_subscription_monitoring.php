<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two feature areas in one pass:
     *
     * 1. PER-USER THEME (Requirement: dual persistence).
     *    `users.theme` stores each account's personalised appearance so it
     *    follows them to any device. The PC-local half of the requirement is
     *    handled client-side (localStorage); this column is the database half.
     *
     * 2. SSA BUSINESS MONITORING (Requirement: institution registry + health).
     *    Subscription + lifecycle fields on `institutions` so the Software Super
     *    Admin can track each tenant's plan, billing state and health.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // { mode, accent, accent_hex, radius, density, font, compact }
            $table->json('theme')->nullable()->after('designation');
        });

        Schema::table('institutions', function (Blueprint $table) {
            // Subscription plan + billing lifecycle: trial | active | past_due |
            // suspended | cancelled.
            $table->string('subscription_plan', 40)->nullable()->after('subsidy_mode');
            $table->string('subscription_status', 30)->default('trial')->after('subscription_plan');
            // Monthly recurring revenue for this tenant, in the platform currency.
            $table->decimal('subscription_amount', 14, 2)->default(0)->after('subscription_status');
            // Billing dates used to flag overdue / expiring accounts.
            $table->date('subscription_started_at')->nullable()->after('subscription_amount');
            $table->date('subscription_renews_at')->nullable()->after('subscription_started_at');
            // Soft platform limits, so the registry can show usage vs. cap.
            $table->unsignedInteger('member_limit')->nullable()->after('subscription_renews_at');
            // Free-form health notes a platform operator can leave on a tenant.
            $table->text('health_notes')->nullable()->after('member_limit');
            // When the SSA last reviewed this tenant, for the health monitor.
            $table->timestamp('last_reviewed_at')->nullable()->after('health_notes');
        });
    }

    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->dropColumn([
                'subscription_plan', 'subscription_status', 'subscription_amount',
                'subscription_started_at', 'subscription_renews_at',
                'member_limit', 'health_notes', 'last_reviewed_at',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('theme');
        });
    }
};
