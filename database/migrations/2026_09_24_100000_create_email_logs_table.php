<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email Log / Outbox.
 *
 * Every email the system dispatches is recorded here by a single global
 * MessageSent listener, so we get a complete, inspectable outbox with:
 *   - delivery status (sent / failed / pending)
 *   - envelope (to, cc, bcc, from, subject)
 *   - the FULL rendered HTML body, so an admin can open an email exactly as the
 *     recipient would see it (invaluable while working against a Mailtrap
 *     sandbox, or to prove what was sent).
 *
 * institution_id scopes the log per workspace (SSA sees everything global; an
 * Institution Admin / Meal Manager sees only their own).
 */

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();

            // Which workspace the email belongs to (null = platform-wide).
            $table->foreignId('institution_id')->nullable()->constrained('institutions')->nullOnDelete();
            // Who triggered the send (the admin / manager).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Envelope.
            $table->string('to')->nullable();
            $table->string('from')->nullable();
            $table->string('cc')->nullable();
            $table->string('bcc')->nullable();
            $table->string('subject')->nullable();

            // A readable key the UI can group by (invitation, password_reset, ...).
            $table->string('kind', 60)->default('general');

            // The Mailable / Notification class that produced this row.
            $table->string('mailable')->nullable();

            // sent | failed | pending
            $table->string('status', 20)->default('sent');
            $table->text('error')->nullable();

            // The full rendered HTML body (long).
            $table->longText('body')->nullable();
            // Plain-text fallback, when the mailer produced one.
            $table->longText('text_body')->nullable();

            // Correlates with the mailer message id when available.
            $table->string('message_id')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['institution_id', 'created_at']);
            $table->index('status');
            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
