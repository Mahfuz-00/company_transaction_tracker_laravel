<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ROLE-SPECIFIC FIRST-TIME ONBOARDING.
 *
 * A brand-new account (self-signup, invited member, provisioned admin, or the
 * platform operator) has no idea what their dashboard offers. This column backs
 * the first-login onboarding manual: it records WHEN the role-specific tour was
 * completed, so it shows exactly once per account and never nags again.
 *
 * NULL  = the user has never seen (or finished) their onboarding.
 * A date = they have - the modal will not reappear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('onboarding_completed_at')->nullable()->after('password_changed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('onboarding_completed_at');
        });
    }
};
