<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUG REPORTS — user-raised defect reports that route to the platform owner.
 *
 * WHAT THIS IS FOR
 * ----------------
 * A non-technical user (Member, Meal Manager, Institution Admin) hits something
 * broken in the field. They cannot file a Jira ticket and they will not email a
 * stack trace. This table gives them a one-click "Report Bug" that captures what
 * the platform owner actually needs to reproduce it:
 *
 *   - WHO  : the reporting user (+ their institution, if any)
 *   - WHERE: `page_url` — the exact screen they were looking at
 *   - WHAT : a description, optional reproduction steps, a severity
 *   - PROOF: an optional screenshot (stored on the `public` disk)
 *   - WHEN : `created_at`
 *
 * WHY `institution_id` IS NULLABLE
 * --------------------------------
 * The reporter is normally bound to a workspace, but the column is nullable so a
 * report can outlive its institution (or be filed from a context with none). The
 * SSA reviews every report across all tenants, so this table is deliberately NOT
 * tenant-scoped in the model — that is the one legitimate exception, exactly like
 * `platform_settings`.
 *
 * WHY THE REPORT IS NEVER DELETED BY A TENANT
 * -------------------------------------------
 * Only the SSA can resolve a report, and resolution is a STATUS change rather
 * than a delete, so the history of what went wrong survives for trend analysis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bug_reports', function (Blueprint $table) {
            $table->id();

            // Who filed it. Nullable so a deleted account does not erase the report.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Which workspace they were in (denormalised for the SSA's filter).
            $table->foreignId('institution_id')->nullable()->constrained('institutions')->nullOnDelete();

            // WHO they were, snapshotted. A report must stay readable even if the
            // account is later renamed, re-roled or removed.
            $table->string('reporter_name')->nullable();
            $table->string('reporter_email')->nullable();
            $table->string('reporter_role', 60)->nullable();

            // WHERE it happened. The exact path is the single most useful field
            // for reproduction, so it is required.
            $table->string('page_url', 2048);

            // WHAT happened.
            $table->string('title', 180);
            $table->text('description');
            $table->text('steps')->nullable();

            // low | normal | high | critical
            $table->string('severity', 20)->default('normal');

            // The browser/device string, so a layout-only bug is identifiable.
            $table->string('user_agent', 512)->nullable();

            // The attachment: a path on the `public` disk, never the raw upload.
            $table->string('screenshot_path', 512)->nullable();
            $table->string('screenshot_name')->nullable();

            // open | acknowledged | resolved | dismissed
            $table->string('status', 20)->default('open');

            // The SSA's resolution trail.
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();

            $table->timestamps();

            // The SSA's inbox is "open reports, newest first" — this index serves
            // that exact query.
            $table->index(['status', 'created_at']);
            $table->index('institution_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bug_reports');
    }
};
