<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_rates', function (Blueprint $table) {
            $table->id();
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->decimal('total_cost', 14, 2)->nullable();
            $table->decimal('cost_per_meal', 14, 4)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_rates');
    }
};
