<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Platform broadcast history.
     *
     * A record of every system-wide announcement the Software Super Admin sent -
     * who it targeted, how many recipients it reached, its severity and who sent
     * it. Kept so the platform's own communications are auditable.
     *
     * Deliberately NOT tenant-scoped (it is a platform-level record), so the
     * model does NOT use the BelongsToInstitution trait.
     */
    public function up(): void
    {
        Schema::create('staff_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            // institution_admins | admins | members | all
            $table->string('audience', 30);
            // info | success | warning | critical
            $table->string('severity', 20)->default('info');
            $table->unsignedInteger('recipients')->default(0);
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_broadcasts');
    }
};
