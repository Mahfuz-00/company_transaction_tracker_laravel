<?php

namespace Tests\Browser\Docs;

use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * Point 20: Comprehensive RAG Documentation File (`docs/rag_vector_forecasting.md`).
 *
 * Verifies:
 * - Markdown documentation exists inside docs/rag_vector_forecasting.md.
 * - Details vector database schema (`forecast_embeddings`).
 * - Documents embedding generation, cosine similarity retrieval, training jobs, and benchmark integration.
 */
class RagDocumentationTest extends DuskTestCase
{
    use DuskSupport;

    public function test_rag_documentation_file_exists_and_contains_architecture_spec(): void
    {
        $this->seedRbac();

        $path = base_path('docs/rag_vector_forecasting.md');
        $this->assertFileExists($path, 'RAG vector documentation file must exist at docs/rag_vector_forecasting.md');

        $content = file_get_contents($path);

        $this->assertStringContainsString('forecast_embeddings', $content);
        $this->assertStringContainsString('Vector Similarity', $content);
        $this->assertStringContainsString('forecast:train-monthly', $content);
        $this->assertStringContainsString('forecast_benchmarks', $content);
        $this->assertStringContainsString('Cost Per Meal', $content);
        $this->assertStringContainsString('Total Expenses', $content);
    }
}
