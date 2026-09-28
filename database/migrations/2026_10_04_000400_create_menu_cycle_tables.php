<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MENU CYCLE & PROCUREMENT FORECASTS.
 *
 * A menu cycle is a repeating plan of meals (e.g. a 7-day rotation). Each day
 * lists the dishes served, and each dish carries its ingredients. From that we
 * derive a PROCUREMENT FORECAST: expected eaters × servings per dish × ingredient
 * quantity = how much to buy, and what it should cost.
 *
 * THREE TABLES
 *   menu_cycles        : the cycle itself (name, length, active)
 *   menu_cycle_days    : one row per day in the cycle, with its dishes (JSON)
 *   menu_ingredients   : the ingredient lines driving the forecast
 *
 * The forecast itself is computed on demand (App\Support\ProcurementForecaster)
 * rather than stored, so it always reflects the latest menu and headcount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            // Length of the rotation in days (e.g. 7).
            $table->unsignedSmallInteger('cycle_length')->default(7);
            // The date the cycle starts repeating from.
            $table->date('starts_on')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['institution_id', 'is_active']);
        });

        Schema::create('menu_cycle_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_cycle_id')->constrained()->cascadeOnDelete();

            // 1..cycle_length - which day of the rotation this is.
            $table->unsignedSmallInteger('day_number');
            $table->string('label')->nullable(); // e.g. "Monday"

            /*
             * The dishes served, as a JSON array:
             *   [{ meal: 'lunch', dish: 'Rice + Chicken', servings: 1, ingredients: [...] }]
             * Stored as JSON because a day's menu is edited as a unit and never
             * queried by individual dish.
             */
            $table->json('dishes')->nullable();

            $table->timestamps();

            $table->unique(['menu_cycle_id', 'day_number']);
        });

        Schema::create('menu_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('menu_cycle_id')->nullable()->constrained()->cascadeOnDelete();

            // Which dish (within a day) this line belongs to. Loose coupling by
            // name so editing a dish's label does not orphan the ingredients.
            $table->string('dish')->nullable();

            $table->string('name');               // e.g. "Rice", "Chicken"
            $table->string('unit', 24)->default('kg');
            // Quantity required PER SERVING of the dish.
            $table->decimal('qty_per_serving', 12, 4)->default(0);
            // Expected unit cost, for turning a quantity into money.
            $table->decimal('unit_cost', 12, 4)->default(0);
            // Optional vendor to buy from.
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->index(['institution_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_ingredients');
        Schema::dropIfExists('menu_cycle_days');
        Schema::dropIfExists('menu_cycles');
    }
};
