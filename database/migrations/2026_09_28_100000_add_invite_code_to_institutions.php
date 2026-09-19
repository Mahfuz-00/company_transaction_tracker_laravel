<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Institution invite code (tenant-mapping for public signup).
     *
     * The public guest registration form requires an invite code so a new user
     * is ALWAYS mapped to a specific institution - never created as an orphaned,
     * institution-less account. The code is:
     *   - unique per institution,
     *   - short + human-shareable (an admin gives it to their staff),
     *   - regenerable by the SSA (rotating it revokes old links).
     *
     * Existing institutions are backfilled with a generated code so nothing is
     * left without one.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('institutions', 'invite_code')) {
            Schema::table('institutions', function (Blueprint $table) {
                $table->string('invite_code', 24)->nullable()->unique()->after('slug');
            });
        }

        // Backfill: give every existing institution a stable, unique code.
        DB::table('institutions')->whereNull('invite_code')->orderBy('id')->each(function ($row) {
            do {
                $code = strtoupper(Str::random(8));
            } while (DB::table('institutions')->where('invite_code', $code)->exists());

            DB::table('institutions')->where('id', $row->id)->update(['invite_code' => $code]);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('institutions', 'invite_code')) {
            Schema::table('institutions', function (Blueprint $table) {
                $table->dropColumn('invite_code');
            });
        }
    }
};
