<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PER-USER LANGUAGE PREFERENCE.
 *
 * A user's language is a PERSONAL setting, like their theme — not an institution
 * setting and not a platform setting. Two admins in the same workspace may
 * legitimately want different languages.
 *
 * NULLABLE ON PURPOSE: null means "never chosen", which lets the resolver fall
 * through to the browser's `Accept-Language` header. A non-null default would
 * make every existing account look like it had deliberately picked English, and
 * the header hint would never apply.
 *
 * There is deliberately NO foreign key or constraint tying this to
 * `config/locales.php`: dropping a language from the config must never require a
 * data migration to clean up orphaned values. The resolver ignores unsupported
 * codes instead (see LocaleManager::isSupported).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Codes are short (en, bn, pt-BR); 10 chars is generous headroom.
            $table->string('locale', 10)->nullable()->after('theme');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
