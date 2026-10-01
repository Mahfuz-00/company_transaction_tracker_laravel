<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VECTOR EMBEDDINGS FOR AUTONOMOUS AI ASSISTANT KNOWLEDGE BASE & RAG PIPELINE.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('assistant_embeddings')) {
            Schema::create('assistant_embeddings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('assistant_knowledge_id')->nullable()->constrained('assistant_knowledge')->cascadeOnDelete();
                $table->foreignId('institution_id')->nullable()->constrained('institutions')->cascadeOnDelete();
                $table->string('source_type', 40)->default('knowledge'); // knowledge, doc, code_feature
                $table->string('source_ref', 255)->nullable();
                $table->text('content');
                $table->json('vector'); // 64-dimension dense float array
                $table->timestamps();

                $table->index(['institution_id', 'source_type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_embeddings');
    }
};
