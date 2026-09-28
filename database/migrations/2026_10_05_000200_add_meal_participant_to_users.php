<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STAFF OPT-IN TO MEALS.
 *
 * Meal Menu voting is open to members AND to staff who actually eat in the mess.
 * An Institution Admin who lives in the dorm they manage has a legitimate opinion
 * about tomorrow's lunch; an admin who never eats there does not.
 *
 * `is_meal_participant` is that opt-in. It defaults to false for staff, and is
 * treated as always-true for a regular Member (whose whole account IS a meal
 * account) - see MealMenuController::isEligibleVoter().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_meal_participant')->default(false)->after('onboarding_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_meal_participant');
        });
    }
};
