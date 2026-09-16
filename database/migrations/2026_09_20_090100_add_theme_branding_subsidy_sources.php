<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Second major upgrade pass:
     *
     *  - Theme customisation per institution/workspace (accent, mode, radius).
     *  - Branding images: institution logo/banner, user avatar.
     *  - Dynamic subsidy funding sources, admin-managed, with a default
     *    percentage split so a funder's share of the pool is explicit.
     *  - Subsidy period anchored to a month so reporting is month-scoped.
     *  - Meal-rate configuration knobs used by the rate calculator.
     */
    public function up(): void
    {
        /* ---------------- Institution: theme + branding ---------------- */
        Schema::table('institutions', function (Blueprint $table) {
            // { accent: 'indigo', mode: 'light', radius: 'md', density: 'comfortable' }
            $table->json('theme')->nullable()->after('currency_settings');
            $table->string('logo_path')->nullable()->after('theme');
            $table->string('banner_path')->nullable()->after('logo_path');
            $table->string('subtitle')->nullable()->after('name');
        });

        /* ---------------- Users: avatar + designation ---------------- */
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('email');
            $table->string('designation')->nullable()->after('avatar_path');
        });

        /* ---------------- Subsidies: month period + percentages ---------------- */
        Schema::table('subsidies', function (Blueprint $table) {
            // Month the subsidy belongs to (YYYY-MM), for strict month filtering.
            $table->string('period_month', 7)->nullable()->after('amount');
            // Share of the pool this funder is expected to carry, 0-100.
            $table->decimal('percentage', 5, 2)->nullable()->after('period_month');
            // Free-form funder name when source = 'other' or a custom source.
            $table->string('source_label')->nullable()->after('source');
        });

        /* ---------------- Dynamic funding sources ---------------- */
        Schema::create('subsidy_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained('institutions')->nullOnDelete();

            $table->string('name');
            // Stable key used by the enum column and reports.
            $table->string('key')->nullable();
            // Default share of the funding pool this source carries (0-100).
            $table->decimal('percentage', 5, 2)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['institution_id', 'name']);
        });

        /* ---------------- Meal rate configuration ---------------- */
        Schema::create('meal_rate_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained('institutions')->nullOnDelete();

            // How the per-meal rate is derived: manual | calculated | hybrid.
            $table->string('rate_mode', 20)->default('calculated');
            // Target share of meal cost expected to come from subsidies (0-100).
            $table->decimal('target_subsidy_ratio', 5, 2)->default(20);
            // Optional manual override used when rate_mode = manual.
            $table->decimal('manual_rate', 10, 4)->nullable();
            // Whether uncovered cost is carried to the next month.
            $table->boolean('carry_forward')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_rate_settings');
        Schema::dropIfExists('subsidy_sources');

        Schema::table('subsidies', function (Blueprint $table) {
            $table->dropColumn(['period_month', 'percentage', 'source_label']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar_path', 'designation']);
        });

        Schema::table('institutions', function (Blueprint $table) {
            $table->dropColumn(['theme', 'logo_path', 'banner_path', 'subtitle']);
        });
    }
};
