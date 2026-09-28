<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RAG / VECTOR FORECASTING.
 *
 * The forecaster answers "how many meals tomorrow, at what cost?" by RETRIEVAL-
 * AUGMENTED GENERATION over the institution's own history:
 *
 *   1. Each past day is summarised into a small feature vector (the "document").
 *   2. Those documents are embedded and stored here in `forecast_embeddings`.
 *   3. To predict, we find the most SIMILAR historical days (cosine similarity)
 *      and use their outcomes as the grounded evidence for the estimate.
 *
 * WHY STORE THE VECTOR AS JSON, NOT A VECTOR COLUMN
 * -------------------------------------------------
 * The project runs on SQLite/MySQL with no pgvector extension, so a portable
 * JSON column plus an in-PHP cosine similarity scan is the honest choice: it
 * works on every supported driver and keeps the feature dependency-free. At the
 * scale of "one row per historical day per institution" (hundreds to a few
 * thousand) the scan is trivially fast. The schema is deliberately shaped so a
 * future swap to a real vector index only changes the search, not the data.
 *
 * BENCHMARKS
 * ----------
 * A brand-new institution has no history to retrieve from. `forecast_benchmarks`
 * holds country-level aggregate figures (e.g. Bangladesh dormitory meal-cost
 * trends) so the feature degrades to a defensible industry baseline instead of
 * failing. `country_code` + `metric` + `period_month` identify a series.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_embeddings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->cascadeOnDelete();

            // The historical day this vector describes.
            $table->date('for_date');

            // The numeric feature vector (see Forecaster::featureVector()).
            $table->json('embedding');

            // The human-readable summary the vector was built from, so the UI can
            // show WHY a past day was considered similar (RAG grounding).
            $table->text('summary')->nullable();

            // The outcomes we want to predict, kept alongside so retrieval can
            // read them without re-querying the ledger.
            $table->unsignedInteger('meals')->default(0);
            $table->unsignedInteger('headcount')->default(0);
            $table->decimal('expense', 16, 2)->default(0);
            $table->decimal('deposits', 16, 2)->default(0);
            $table->decimal('cost_per_meal', 12, 4)->default(0);

            // Which embedding "model" produced the vector, so a future change of
            // dimensions can coexist without corrupting old comparisons.
            $table->string('model', 48)->default('v1-local');

            $table->timestamps();

            $table->unique(['institution_id', 'for_date', 'model']);
            $table->index(['institution_id', 'for_date']);
        });

        Schema::create('forecast_benchmarks', function (Blueprint $table) {
            $table->id();

            // ISO country code the benchmark describes (BD = Bangladesh).
            $table->string('country_code', 8);
            // e.g. cost_per_meal | meals_per_member | daily_meal_rate | subsidy_pct
            $table->string('metric', 48);
            // The month this datapoint belongs to (YYYY-MM-01).
            $table->date('period_month');

            $table->decimal('value', 16, 4);
            $table->string('unit', 24)->nullable();

            // Provenance, so a figure on screen can always be attributed.
            $table->string('source', 120)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['country_code', 'metric', 'period_month']);
            $table->index(['country_code', 'metric']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_benchmarks');
        Schema::dropIfExists('forecast_embeddings');
    }
};
