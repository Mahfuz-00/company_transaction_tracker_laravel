<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PLATFORM-LEVEL settings - a global key/value store owned by the Software
 * Super Admin (NOT tenant-scoped).
 *
 * The first consumer is the SMTP / mail configuration: the SSA can point the
 * whole platform at an SMTP relay from the UI, with no code change or redeploy.
 * Storing it as key/value (rather than a dedicated column per setting) keeps
 * this extensible for the next global setting without another migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
