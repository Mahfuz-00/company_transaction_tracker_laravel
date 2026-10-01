<?php

namespace App\Console\Commands;

use App\Models\AssistantEmbedding;
use App\Models\AssistantKnowledge;
use App\Support\VectorPipeline;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class IngestAssistantKnowledgeCommand extends Command
{
    protected $signature = 'assistant:ingest {--force : Re-index all records}';

    protected $description = 'Ingest codebase features, markdown documentation, and assistant knowledge into vector database';

    public function handle(): int
    {
        $this->info('Starting Assistant Vector RAG ingestion pipeline...');
        $indexedCount = 0;

        // 1. Index AssistantKnowledge entries
        $knowledges = AssistantKnowledge::all();
        foreach ($knowledges as $item) {
            $textToEmbed = $item->question.' '.$item->answer.' '.implode(' ', (array) $item->phrasings).' '.implode(' ', (array) $item->keywords);
            $vector = VectorPipeline::embed($textToEmbed);

            AssistantEmbedding::updateOrCreate(
                [
                    'assistant_knowledge_id' => $item->id,
                    'source_type' => 'knowledge',
                ],
                [
                    'institution_id' => $item->institution_id,
                    'source_ref' => 'knowledge_'.$item->id,
                    'content' => $item->question."\n".$item->answer,
                    'vector' => $vector,
                ]
            );
            $indexedCount++;
        }

        // 2. Index Documentation in docs/ directory
        $docsPath = base_path('docs');
        if (File::isDirectory($docsPath)) {
            $files = File::allFiles($docsPath);
            foreach ($files as $file) {
                if (in_array($file->getExtension(), ['md', 'txt'])) {
                    $content = $file->getContents();
                    $relPath = $file->getRelativePathname();

                    // Split content into paragraph chunks
                    $chunks = preg_split('/\n\s*\n/', $content);
                    foreach ($chunks as $idx => $chunk) {
                        $chunk = trim($chunk);
                        if (mb_strlen($chunk) < 30) {
                            continue;
                        }
                        $vector = VectorPipeline::embed($chunk);

                        AssistantEmbedding::updateOrCreate(
                            [
                                'source_type' => 'doc',
                                'source_ref' => $relPath.'#'.$idx,
                            ],
                            [
                                'assistant_knowledge_id' => null,
                                'institution_id' => null,
                                'content' => $chunk,
                                'vector' => $vector,
                            ]
                        );
                        $indexedCount++;
                    }
                }
            }
        }

        $this->info("Vector RAG ingestion complete! Indexed {$indexedCount} items.");

        return 0;
    }
}
