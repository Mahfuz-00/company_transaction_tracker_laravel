<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DYNAMIC REPORTS BUILDER - SAVED QUERIES.
 *
 * Instead of rigid, hand-coded report screens, a user composes a query over the
 * finance engine (dataset + metric + group-by + filters + range) and SAVES it.
 * A saved report is re-runnable, shareable and exportable.
 *
 * `definition` holds the JSON spec (see App\Support\ReportBuilder::DATASETS for
 * the allowed datasets/metrics - the builder validates against that whitelist so
 * a stored definition can never request an arbitrary column).
 *
 * `is_shared` lets an Institution Admin publish a report to the whole workspace;
 * a private report is visible only to its owner. Both are still tenant-scoped by
 * the institution_id column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            // The query spec exactly as the builder emits it.
            $table->json('definition');

            // Visible to the whole workspace (not just the creator).
            $table->boolean('is_shared')->default(false);

            // Optional: pin to the dashboard as a widget.
            $table->boolean('is_pinned')->default(false);

            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('run_count')->default(0);

            $table->timestamps();

            $table->index(['institution_id', 'is_shared']);
            $table->index(['user_id', 'is_shared']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_reports');
    }
};
