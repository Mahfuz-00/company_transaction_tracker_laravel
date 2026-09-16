<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multi-vendor + multi-institution upgrade.
     *
     * - Every vendor, department and member now belongs to an institution so a
     *   single deployment can host several (company offices, dorms, messes).
     * - Vendors gain recurring-purchase metadata and a flag marking the
     *   institution itself as the primary vendor / hub of the ecosystem.
     * - The institution gains basket-affecting settings: how subsidies are
     *   applied by default, and the global currency configuration.
     */
    public function up(): void
    {
        /* ---------------- Vendors: recurring purchase support ---------------- */
        Schema::table('vendors', function (Blueprint $table) {
            // Marks the institution acting as its own primary supplier/hub.
            $table->boolean('is_institution_hub')->default(false)->after('category');
            // Recurring purchase cadence for this supplier, e.g. daily/weekly.
            $table->string('recurrence', 20)->nullable()->after('is_institution_hub');
            // Standard lead time before delivery, in days.
            $table->unsignedSmallInteger('lead_time_days')->nullable()->after('recurrence');
            // Typical value of one recurring order, for planning.
            $table->decimal('recurring_amount', 14, 2)->nullable()->after('lead_time_days');
        });

        /* ---------------- Departments / Students: institution scope ---------------- */
        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('institution_id')->nullable()->after('id')
                ->constrained('institutions')->nullOnDelete();
        });

        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('institution_id')->nullable()->after('id')
                ->constrained('institutions')->nullOnDelete();
            // The user who owns/manages this member record (manager or the
            // member's own linked account). Distinct from user_id (the login).
            $table->foreignId('manager_id')->nullable()->after('user_id')
                ->constrained('users')->nullOnDelete();
            // Index the roll for fast lookup / uniqueness per institution.
            $table->string('roll', 100)->nullable()->change();
            $table->index(['institution_id', 'roll']);
        });

        /* ---------------- Users: institution scope + invited flag ---------------- */
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('institution_id')->nullable()->after('id')
                ->constrained('institutions')->nullOnDelete();
            // True until the invitee completes signup; they cannot log in before.
            $table->boolean('invitation_pending')->default(false)->after('status');
        });

        /* ---------------- Deposits: subsidy linkage ---------------- */
        Schema::table('deposits', function (Blueprint $table) {
            // personal | subsidy | credit  - keeps subsidy money distinct.
            $table->string('kind', 20)->default('personal')->after('amount');
            $table->foreignId('subsidy_id')->nullable()->after('kind')
                ->constrained('subsidies')->nullOnDelete();
        });

        /* ---------------- Institution: currency + subsidy settings ---------------- */
        Schema::table('institutions', function (Blueprint $table) {
            // Global currency configuration managed by the Software Super Admin.
            // { symbol, position, decimal_separator, thousands_separator,
            //   decimal_precision, numbering_system, abbreviations }
            $table->json('currency_settings')->nullable()->after('currency_code');
            // Default subsidy application mode for new subsidies.
            $table->string('subsidy_mode', 30)->default('pool')->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->dropColumn(['currency_settings', 'subsidy_mode']);
        });

        Schema::table('deposits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subsidy_id');
            $table->dropColumn('kind');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('institution_id');
            $table->dropColumn('invitation_pending');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex(['institution_id', 'roll']);
            $table->dropConstrainedForeignId('manager_id');
            $table->dropConstrainedForeignId('institution_id');
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('institution_id');
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['is_institution_hub', 'recurrence', 'lead_time_days', 'recurring_amount']);
        });
    }
};
