<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forced password change for admin-provisioned ("demo password") accounts.
 *
 * When SMTP is unavailable, an admin creates a user with a temporary password
 * and the account is flagged `must_change_password`. A middleware then forces
 * that user to the change-password screen before they can use anything else.
 *
 * Once SMTP is wired up and creation switches to invitation-only, these columns
 * simply stay false and the flag has no effect - so the fallback is safe to keep.
 */

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('invitation_pending');
            $table->timestamp('password_changed_at')->nullable()->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['must_change_password', 'password_changed_at']);
        });
    }
};
