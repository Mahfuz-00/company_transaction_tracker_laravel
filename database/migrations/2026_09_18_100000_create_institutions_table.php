<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The institution is the top-level tenant: a dorm, a hall, an office, a mess.
 *
 * Its `type` drives terminology across the whole UI (a "student" in a dorm is
 * a "member" in a company), and `terminology` holds per-key overrides for
 * anything the presets don't cover.
 *
 * The app currently runs one institution, but every table that will later need
 * scoping already carries the shape to do it - adding institution_id to the
 * domain tables is the only step left for true multi-tenancy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institutions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();

            // company | university_dorm | college_dorm | general_mess | custom
            $table->string('type')->default('general_mess');

            $table->string('currency_code')->nullable();
            $table->string('timezone')->default('UTC');
            $table->string('address')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();

            // Per-key terminology overrides, merged over the type preset.
            // e.g. {"member": "Student", "deposit": "Contribution"}
            $table->json('terminology')->nullable();

            $table->json('settings')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institutions');
    }
};
