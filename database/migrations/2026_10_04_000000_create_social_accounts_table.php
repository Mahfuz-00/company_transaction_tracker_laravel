<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SSO / OAUTH IDENTITY LINKING.
 *
 * Institutions want their staff to sign in with the Google Workspace or
 * Microsoft Entra account they already have, rather than yet another password.
 *
 * `social_accounts` is the LINK TABLE between a local `users` row and an external
 * identity. One user may hold several (a Google AND a Microsoft account), so it
 * is a one-to-many, keyed uniquely on (provider, provider_user_id):
 *
 *   - `provider`          : 'google' | 'microsoft'
 *   - `provider_user_id`  : the IdP's stable subject id (`sub` / `oid`)
 *   - `email`             : the email the IdP asserted at link time
 *   - `tenant_id`         : the Entra `tid` (directory) or Google `hd` (domain),
 *                           which is what ties an identity to an INSTITUTION
 *   - `institution_id`    : the workspace this link was made inside
 *
 * SECURITY: the unique (provider, provider_user_id) index is what makes a
 * callback idempotent AND prevents two local accounts claiming the same identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();

            $table->string('provider', 32);
            $table->string('provider_user_id', 191);
            $table->string('email')->nullable();
            // The IdP directory/domain identifier (Entra tid, Google hd).
            $table->string('tenant_id', 191)->nullable();
            $table->string('name')->nullable();
            $table->string('avatar_url')->nullable();

            // OAuth tokens, if we ever need to call the IdP on the user's behalf.
            // Encrypted by the model cast, never returned to the browser.
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('last_login_at')->nullable();

            $table->timestamps();

            // One identity per provider, globally.
            $table->unique(['provider', 'provider_user_id']);
            // Fast lookup of a user's linked identities.
            $table->index(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
