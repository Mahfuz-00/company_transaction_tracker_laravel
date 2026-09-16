<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Secure email invitations. An admin captures the member's email and we
     * send a signed link; the member sets their own password, so no default
     * password ever exists in the system.
     */
    public function up(): void
    {
        Schema::create('member_invitations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('institution_id')->nullable()->constrained('institutions')->nullOnDelete();
            // The member record being invited to create a login for.
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();

            $table->string('email');
            $table->string('name')->nullable();
            // Role the invited user receives on acceptance.
            $table->string('role')->default('Member');

            // Opaque token stored hashed; the plaintext only ever travels by email.
            $table->string('token', 64)->unique();

            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();

            $table->timestamps();

            $table->index(['email', 'accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_invitations');
    }
};
