<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE INSTITUTION'S COUNTRY.
 *
 * Used by the RAG forecasting engine as the fallback anchor: when a workspace has
 * under the minimum months of its own history, the estimate is built from that
 * COUNTRY's published aggregates (e.g. Bangladesh dormitory meal costs). A plain
 * ISO-3166 alpha-2 code, defaulting to BD (the primary market).
 *
 * WHY A SEPARATE MIGRATION
 * ------------------------
 * The column was originally added inside add_sso_to_institutions, which had ALREADY
 * been recorded as run - so on an existing database the extra column was silently
 * skipped while a freshly-migrated database (like the Dusk one) had it. That
 * divergence made the forecasting tests pass in isolation and fail in a full run.
 * A standalone, idempotent migration guarantees the column everywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('institutions', 'country_code')) {
            return;
        }

        Schema::table('institutions', function (Blueprint $table) {
            $table->string('country_code', 2)->nullable()->default('BD')->after('sso_provider_hint');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('institutions', 'country_code')) {
            return;
        }

        Schema::table('institutions', function (Blueprint $table) {
            $table->dropColumn('country_code');
        });
    }
};
