<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Institution trial lifecycle + onboarding mode.
     *
     * The SSA can now onboard an institution one of two ways:
     *   - 'trial'      : a 7-day free trial with limited access, expiring on
     *                    `trial_ends_at`.
     *   - 'subscription': an immediate permanent subscription (no trial).
     *
     * These columns drive the Trial Management module, the automated reminder
     * command, and the limited-access feature gate while a trial is running.
     */
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            // 'trial' | 'subscription' - how the workspace was provisioned.
            $table->string('onboarding_mode', 20)->default('subscription')->after('subscription_status');
            // When the 7-day trial started and when it lapses.
            $table->timestamp('trial_started_at')->nullable()->after('onboarding_mode');
            $table->timestamp('trial_ends_at')->nullable()->after('trial_started_at');
            // When the last upgrade reminder was sent, so the automated command
            // never spams an institution more than once per window.
            $table->timestamp('trial_reminder_sent_at')->nullable()->after('trial_ends_at');
            // Set when the SSA (or the institution) converts off the trial.
            $table->timestamp('converted_at')->nullable()->after('trial_reminder_sent_at');
        });

        // Backfill: any institution already flagged as 'trial' gets a sensible
        // window ending 7 days from its creation, so existing rows behave.
        DB::table('institutions')
            ->where('subscription_status', 'trial')
            ->whereNull('trial_ends_at')
            ->update([
                'onboarding_mode' => 'trial',
                'trial_started_at' => DB::raw('created_at'),
                'trial_ends_at' => DB::raw("datetime(created_at, '+7 days')"),
            ]);
    }

    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->dropColumn([
                'onboarding_mode',
                'trial_started_at',
                'trial_ends_at',
                'trial_reminder_sent_at',
                'converted_at',
            ]);
        });
    }
};
