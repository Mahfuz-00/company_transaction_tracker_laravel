<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MEMBER MEAL SCHEDULING ("I will / will not take meals").
 *
 * A member tells the meal manager, in advance, whether they will eat on a given
 * date or range of dates. This is deliberately NOT a meal entry: it is a
 * DECLARATION of intent that the manager consults (and can act on) before
 * recording meals, which is what reduces the "wrongful meal count" disputes the
 * claim module then has to fix.
 *
 * RECURRENCE
 * ----------
 *   `recurrence` records HOW the notice repeats:
 *     one_time  - only the range below
 *     daily     - every day in the range
 *     weekly    - the same weekday(s) in the range
 *     custom    - an explicit interval of `interval_days`
 *
 * The resolved set of covered dates is computed on read (`MealSchedule::covers()`)
 * rather than expanded into rows, so a "not eating for the next 6 months" notice
 * stays ONE row instead of 180.
 *
 * TENANCY
 * -------
 * `institution_id` is denormalised so a query is scopeable without a join through
 * `students` (the same reasoning documented on the ledger tables).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_schedules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('institution_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();

            // The member's account (nullable: a roster row may have no login).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // 'off' = will NOT take meals; 'on' = will take meals (a member may
            // re-confirm after previously opting out).
            $table->string('status', 10)->default('off');

            $table->string('recurrence', 20)->default('one_time');

            // Inclusive date range the notice applies to.
            $table->date('starts_on');
            $table->date('ends_on')->nullable();

            // For recurrence='custom': every N days within the range.
            $table->unsignedSmallInteger('interval_days')->nullable();

            // For recurrence='weekly': CSV of weekdays (0=Sun..6=Sat).
            $table->string('weekdays', 20)->nullable();

            // Which meals the notice covers (null/empty = all three).
            $table->boolean('breakfast')->default(true);
            $table->boolean('lunch')->default(true);
            $table->boolean('dinner')->default(true);

            $table->string('reason', 255)->nullable();

            // 'pending' | 'acknowledged' | 'cancelled'
            $table->string('manager_status', 20)->default('pending');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();

            $table->timestamps();

            $table->index(['institution_id', 'starts_on', 'ends_on']);
            $table->index(['student_id', 'starts_on']);
            $table->index('manager_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_schedules');
    }
};
