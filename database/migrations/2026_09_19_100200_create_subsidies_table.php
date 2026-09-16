<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Institutional subsidies - funds injected by the university authority,
     * company management, or college administration on behalf of the whole
     * body (or a group), as opposed to a personal member deposit.
     *
     * Kept in its own table so subsidy totals are never mixed with member
     * deposits in reports or balances.
     */
    public function up(): void
    {
        Schema::create('subsidies', function (Blueprint $table) {
            $table->id();

            $table->foreignId('institution_id')->nullable()->constrained('institutions')->nullOnDelete();

            // Where the money came from - drives reporting buckets.
            // university_authority | company_management | college_administration |
            // government_grant | donation | other
            $table->string('source', 60);

            // Optional scope: whole institution, one department, or one member.
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();

            // How the subsidy is applied:
            //   pool            - adds to the common meal pool
            //   per_member      - distributed evenly across active members
            //   credit_behind   - sits as a reserve, only tapped when a member's
            //                     own deposit is exhausted (strict balance rule)
            $table->string('apply_mode', 30)->default('pool');

            $table->decimal('amount', 14, 2);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();

            $table->string('status', 20)->default('active'); // active | reversed
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['institution_id', 'created_at']);
            $table->index('source');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subsidies');
    }
};
