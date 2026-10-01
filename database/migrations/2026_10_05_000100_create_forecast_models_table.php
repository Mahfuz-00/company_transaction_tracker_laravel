<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TRAINED FORECAST MODEL WEIGHTS + THE 3-MONTH ROLLING FORECAST.
 *
 * WHY THIS TABLE EXISTS
 * ---------------------
 * The forecaster previously recomputed everything from the raw ledger on every
 * page load. That is fine for a 7-day view, but it made two things impossible:
 *
 *   1. A MONTHLY TRAINING RUN had nowhere to store what it learned, so the
 *      "trained" state could not be inspected, audited or compared month to
 *      month. `forecast:train-monthly` produced numbers that existed only for the
 *      duration of the command.
 *
 *   2. A ROLLING 3-MONTH FORECAST could not be PERSISTED, so the projection a
 *      manager saw on the 1st silently changed every time they reloaded, and
 *      there was no record of what had been forecast versus what actually
 *      happened. A forecast you cannot look back on is not a forecast.
 *
 * ONE ROW PER (institution, month, model).
 *
 * WHAT IS STORED
 *   - `weights`     : the learned per-weekday meal-count multipliers plus the
 *                     trailing baselines the model converged on. Stored as JSON
 *                     because the shape is model-specific and should be able to
 *                     evolve without a migration.
 *   - `metrics`     : the training diagnostics (days trained, months of history,
 *                     mean absolute error). Kept so a later month can be compared
 *                     against an earlier one honestly.
 *   - `forecast`    : the PERSISTED rolling projection — an array of the next
 *                     three months, each with its own `basis`.
 *
 * WHY `basis` IS PER ROW AND NOT GLOBAL
 *   An institution can have enough history for a data-driven model in month one
 *   and fall back to country benchmarks in another (a gap in recording). The
 *   projection's `basis` therefore travels WITH the row so a benchmark-derived
 *   month is never presented as though it were learned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->cascadeOnDelete();

            // The month this training run belongs to (YYYY-MM-01). A re-run in the
            // same month REPLACES the row rather than appending, so the table holds
            // one authoritative state per month.
            $table->date('period_month');

            // Which model produced the weights, so a future dimension/shape change
            // can coexist with the old rows instead of corrupting comparisons.
            $table->string('model', 48)->default('monthly-v1');

            /*
             * HOW THIS MONTH'S STATE WAS DERIVED.
             *   trained   - there was enough history to fit the weights
             *   benchmark - too little history; country aggregates were used
             *   empty     - no data at all; everything cleanly zero
             */
            $table->string('basis', 24)->default('empty');

            // The learned weights (weekday multipliers, baselines) as JSON.
            $table->json('weights')->nullable();

            // Training diagnostics (days, months of history, error).
            $table->json('metrics')->nullable();

            /*
             * THE PERSISTED 3-MONTH ROLLING FORECAST.
             *
             * A JSON array of {month, label, meals, rate, cost, basis}. Persisted on
             * the FIRST day of each month (see forecast:train-monthly --rolling) so
             * the projection is stable and auditable rather than recomputed on every
             * page view.
             */
            $table->json('forecast')->nullable();

            // When the rolling forecast for this month was last written.
            $table->timestamp('forecast_generated_at')->nullable();

            $table->timestamps();

            // One authoritative row per institution per month per model.
            $table->unique(['institution_id', 'period_month', 'model']);
            $table->index(['institution_id', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_models');
    }
};
