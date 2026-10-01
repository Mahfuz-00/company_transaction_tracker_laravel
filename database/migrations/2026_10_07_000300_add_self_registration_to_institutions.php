<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PAID SELF-REGISTRATION + CONFIGURABLE MEAL-MANAGER ROLE COUNT.
 *
 * TWO COLUMN GROUPS, one concern each.
 *
 * (A) SELF-REGISTRATION FIELDS on `institutions`
 * ----------------------------------------------
 * An institution can now be created from the PUBLIC onboarding page by the
 * institution itself, rather than only by the SSA. That introduces a lifecycle
 * the SSA-driven path never needed:
 *
 *   onboarding_status : 'draft' | 'awaiting_payment' | 'active' | 'abandoned'
 *   signup_reference  : a stable, human-quotable key for the pending signup, used
 *                       to RESUME an incomplete registration instead of creating
 *                       a duplicate (the duplicate-prevention requirement).
 *   payment_gateway   : which gateway the pending payment is for
 *   payment_reference : the gateway's own reference for that payment
 *
 * A self-registered institution starts as 'awaiting_payment' and is INACTIVE:
 * its admin cannot sign in until the payment clears, which is enforced in
 * AuthenticatedSessionController rather than by hiding the login form.
 *
 * (B) `meal_manager_roles` on `institutions`
 * ------------------------------------------
 * How many Meal Manager seats this institution is configured for. `0` means
 * "unlimited" (the historical behaviour); a positive number is a soft cap an
 * admin sets for their own workspace, checked when a manager role is granted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            if (! Schema::hasColumn('institutions', 'onboarding_status')) {
                $table->string('onboarding_status', 24)->nullable()->after('onboarding_mode');
            }

            if (! Schema::hasColumn('institutions', 'signup_reference')) {
                $table->string('signup_reference', 64)->nullable()->unique()->after('onboarding_status');
            }

            if (! Schema::hasColumn('institutions', 'payment_gateway')) {
                $table->string('payment_gateway', 40)->nullable()->after('signup_reference');
            }

            if (! Schema::hasColumn('institutions', 'payment_reference')) {
                $table->string('payment_reference', 120)->nullable()->after('payment_gateway');
            }

            if (! Schema::hasColumn('institutions', 'meal_manager_roles')) {
                // 0 = unlimited. Positive = a soft cap on manager seats.
                $table->unsignedSmallInteger('meal_manager_roles')->default(0)
                    ->after('member_limit');
            }
        });
    }

    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            foreach ([
                'onboarding_status',
                'signup_reference',
                'payment_gateway',
                'payment_reference',
                'meal_manager_roles',
            ] as $column) {
                if (Schema::hasColumn('institutions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
