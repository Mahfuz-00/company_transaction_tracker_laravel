<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INSTITUTION SSO CONFIGURATION.
 *
 * An institution that uses Google Workspace or Microsoft Entra for its staff
 * needs to say WHICH directory is theirs, otherwise "Sign in with Google" would
 * let any Google account in the world attempt entry.
 *
 *   sso_domain          : the email domain allowed through SSO (e.g. "nsu.edu.bd").
 *                         A sign-in whose asserted email is outside this domain is
 *                         refused, even if the provider accepted it.
 *   sso_enabled         : whether the institution offers SSO at all. Off by
 *                         default, so existing deployments are unaffected.
 *   sso_provider_hint   : which provider to present first ('google' | 'microsoft').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->boolean('sso_enabled')->default(false)->after('invite_code');
            $table->string('sso_domain')->nullable()->after('sso_enabled');
            $table->string('sso_provider_hint', 32)->nullable()->after('sso_domain');
        });

        /*
         * NOTE: `institutions.country_code` (the forecasting benchmark anchor) is
         * added by its OWN migration (2026_10_06_000100), NOT here. Adding it in
         * this file's Schema::table() block only worked on a database that had
         * never run this migration - on an existing deployment the column was
         * silently skipped, which made the forecasting tests pass in isolation and
         * fail in a full run. A standalone migration guarantees it everywhere.
         */
    }

    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->dropColumn(['sso_enabled', 'sso_domain', 'sso_provider_hint']);
        });
    }
};
