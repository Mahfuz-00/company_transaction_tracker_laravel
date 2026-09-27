<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MEAL MENU & VOTING MODULE.
 *
 * Staff propose a menu; eligible members vote on it; an Admin or Meal Manager
 * APPROVES it before it becomes active. Nothing a member sees as "the menu" is
 * ever unapproved - that is the control the module exists to provide.
 *
 * THREE TABLES
 *   meal_menus         : one proposed menu for a date, with its lifecycle status
 *   meal_menu_options  : the dishes members choose between (a menu has many)
 *   meal_menu_votes    : one member's choice, unique per (menu, voter)
 *
 * WHO MAY VOTE
 *   Regular members, Institution Admins and Meal Managers - the last two only if
 *   they have "opted in" to meals (a staff member who eats in the mess). That is
 *   captured by `User::is_meal_participant`, applied in the controller.
 *
 * STATUS LIFECYCLE
 *   draft     -> being composed, not visible to members
 *   voting    -> open; members may cast/change one vote each
 *   approved  -> an Admin/Manager has approved it; this is the ACTIVE menu
 *   rejected  -> an Admin/Manager declined it (with a reason)
 *   cancelled -> withdrawn by its creator (withdrawn before approval)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->cascadeOnDelete();

            // The meal slot and the day it applies to.
            $table->string('meal_type', 24)->default('lunch'); // breakfast|lunch|dinner|snack
            $table->date('menu_date');

            $table->string('title');
            $table->text('description')->nullable();

            $table->string('status', 24)->default('draft');

            // Voting window (nullable => open until the status changes).
            $table->timestamp('voting_opens_at')->nullable();
            $table->timestamp('voting_closes_at')->nullable();

            // Whether members may change their vote after casting it.
            $table->boolean('allow_vote_changes')->default(true);

            // Who proposed it, and who approved/rejected it.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_note')->nullable();

            $table->timestamps();

            $table->index(['institution_id', 'status']);
            $table->index(['institution_id', 'menu_date']);
            // One PRE-APPROVAL menu per slot/day would be confusing, but a rejected
            // proposal may legitimately be superseded by a new one - so uniqueness
            // is enforced in the controller, not the schema.
        });

        Schema::create('meal_menu_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_menu_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            // What it is expected to cost per serving - drives the procurement
            // estimate for the winning option.
            $table->decimal('estimated_cost', 12, 2)->default(0);
            // Lets a proposer mark one option as the recommended default.
            $table->boolean('is_recommended')->default(false);

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index('meal_menu_id');
        });

        Schema::create('meal_menu_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_menu_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_menu_option_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();

            $table->string('comment')->nullable();

            $table->timestamps();

            // ONE VOTE PER MEMBER PER MENU - the integrity rule of the whole module.
            // A member changing their mind UPDATES this row rather than adding one.
            $table->unique(['meal_menu_id', 'user_id']);
            $table->index(['meal_menu_id', 'meal_menu_option_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_menu_votes');
        Schema::dropIfExists('meal_menu_options');
        Schema::dropIfExists('meal_menus');
    }
};
