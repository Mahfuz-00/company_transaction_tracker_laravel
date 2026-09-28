<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FINANCIAL & OPERATIONAL ANOMALY DETECTION.
 *
 * A detector scans the ledger and roster and writes one row per suspicious
 * finding. Crucially it is IDEMPOTENT: each anomaly carries a `fingerprint` (a
 * hash of kind + subject + the window it was detected in) with a UNIQUE index,
 * so re-running the scan updates/ignores existing findings instead of flooding
 * the queue with duplicates.
 *
 * KIND values (see App\Support\AnomalyDetector):
 *   - duplicate_deposit   : same member, same amount, within a short window
 *   - meal_spike          : a day/member far above the member's own baseline
 *   - negative_balance    : a member whose balance has gone past a threshold
 *   - unusual_expense     : a single expense far above the category's norm
 *   - dormant_reactivation: activity after a long dormancy
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anomalies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->cascadeOnDelete();

            // What kind of problem, and how serious.
            $table->string('kind', 48);
            $table->string('severity', 16)->default('warning'); // info | warning | critical

            // The record the finding points at (polymorphic-ish, kept explicit so
            // the monitor can link straight to the deposit / member / expense).
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title');
            $table->text('detail')->nullable();

            // Numeric context so the UI can rank by materiality.
            $table->decimal('amount', 16, 2)->nullable();
            $table->decimal('score', 10, 4)->nullable();

            // The window the scan covered, and the date the anomaly refers to.
            $table->date('detected_for')->nullable();

            // Review workflow.
            $table->string('status', 16)->default('open'); // open | acknowledged | dismissed | resolved
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            // De-duplication key: kind + subject + window, hashed.
            $table->string('fingerprint', 64)->unique();

            $table->timestamps();

            $table->index(['institution_id', 'status']);
            $table->index(['kind', 'status']);
            $table->index('detected_for');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anomalies');
    }
};
