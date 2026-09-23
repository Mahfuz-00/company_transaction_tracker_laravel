<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Institution-scoped broadcasts.
 *
 * Deliberately SEPARATE from `staff_broadcasts`, which is platform-wide and has
 * no institution column. THIS table is tenant-owned: the matching model
 * (`App\Models\InstitutionBroadcast`) uses the `BelongsToInstitution` trait, so
 * every query is auto-scoped to the acting institution and every insert is
 * auto-stamped with its id. That trait + scope is what guarantees an Institution
 * Admin can never read or write another workspace's broadcast history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institution_broadcasts', function (Blueprint $table) {
            $table->id();

            // The tenant key. Cascades with the institution so a deleted
            // workspace takes its broadcast history with it.
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();

            $table->string('title');
            $table->text('body');

            // Who inside the institution was targeted (institution_admins |
            // admins | members) and how the notice is styled.
            $table->string('audience', 30);
            $table->string('severity', 20)->default('info');

            // How many users actually received it (recorded for the history view).
            $table->unsignedInteger('recipients')->default(0);

            // The sending admin; nulled if that account is later deleted.
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The history list is always "latest first" within one institution.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institution_broadcasts');
    }
};
