<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Immutable audit trail. One row per create / update / delete / login
     * across every tracked model. Records are never updated or deleted by the
     * app itself, so the log can be trusted as a financial-grade history.
     */
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            // Who acted. Null once the user is deleted - the log survives them.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Denormalised so the row still reads correctly after a user delete.
            $table->string('user_name')->nullable();
            $table->string('user_email')->nullable();

            // Which institution the action belongs to (multi-tenant scoping).
            $table->foreignId('institution_id')->nullable()->constrained('institutions')->nullOnDelete();

            // What happened: created | updated | deleted | login | logout | invited ...
            $table->string('event', 40);
            // Short human summary, e.g. 'created Student "Farhan Hossain"'.
            $table->string('description');

            // The subject the action targeted.
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();

            // Field-level diff: { field: { old, new }.
            $table->json('properties')->nullable();

            // Request context, for forensics.
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();

            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['institution_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
