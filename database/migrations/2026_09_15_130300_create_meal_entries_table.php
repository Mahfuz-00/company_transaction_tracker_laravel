<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedSmallInteger('breakfast')->default(0);
            $table->unsignedSmallInteger('lunch')->default(0);
            $table->unsignedSmallInteger('dinner')->default(0);
            $table->unsignedSmallInteger('total_meals')->virtualAs('breakfast + lunch + dinner');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_entries');
    }
};
