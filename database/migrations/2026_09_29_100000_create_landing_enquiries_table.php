<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Landing enquiries / demo requests.
     *
     * Previously a landing submission was only written to the activity log, so
     * the SSA could SEE it but could not ACT on it. This table makes an enquiry a
     * first-class, actionable record: the SSA can approve it, which provisions
     * the institution on a trial (or on a plan) straight from the dashboard.
     *
     * Platform-level (NOT tenant-scoped) - an enquiry predates any institution.
     */
    public function up(): void
    {
        Schema::create('landing_enquiries', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('email');
            $table->string('institution_name')->nullable();
            // corporate | university | college | mess (free-form)
            $table->string('institution_type', 60)->nullable();
            $table->text('message')->nullable();

            // Workflow state: new | contacted | approved | rejected
            $table->string('status', 20)->default('new');
            // Set once the SSA provisions the institution from this enquiry.
            $table->foreignId('institution_id')->nullable()->constrained('institutions')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            // Free-form note the SSA leaves when contacting / rejecting.
            $table->text('review_notes')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_enquiries');
    }
};
