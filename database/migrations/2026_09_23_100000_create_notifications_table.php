<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app notification store.
 *
 * This is the standard Laravel database-notifications table (the `Notifiable`
 * trait already on the User model writes here). We only customise it slightly:
 *   - institution_id, so a notification can be scoped/cleaned per workspace.
 *   - type, kept as a readable key alongside the morph `type` column name clash
 *     is avoided by using the standard schema and reading the class basename.
 *
 * Announcements (broadcast to a whole institution) reuse the same table: one row
 * per recipient, so per-user read state works with no extra join.
 */

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Standard morphs (notifiable_type/id) - the recipient.
            $table->string('type');
            $table->morphs('notifiable');
            // Denormalised for scoping/cleanup; nullable for platform-wide.
            $table->foreignId('institution_id')->nullable()->after('notifiable_id')
                ->constrained('institutions')->nullOnDelete();
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
            $table->index('institution_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
