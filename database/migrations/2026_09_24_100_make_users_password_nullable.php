<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-step user creation support.
 *
 * An invited user exists in the database BEFORE they set a password (that is
 * what "incomplete" means). So the password column must be nullable, and we
 * track whether account setup has been completed so the UI and controllers can
 * distinguish "pending setup" from "active".
 *
 *   - password         : NULL until the invitee sets one via the signed link.
 *   - setup_completed_at: stamped when they finish; NULL = still pending.
 */

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
            $table->timestamp('setup_completed_at')->nullable()->after('password_changed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('setup_completed_at');
        });
    }
};
