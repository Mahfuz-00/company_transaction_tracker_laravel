<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssistantEmbedding extends Model
{
    protected $table = 'assistant_embeddings';

    protected $fillable = [
        'assistant_knowledge_id',
        'institution_id',
        'source_type',
        'source_ref',
        'content',
        'vector',
    ];

    protected $casts = [
        'vector' => 'array',
    ];

    public function knowledge()
    {
        return $this->belongsTo(AssistantKnowledge::class, 'assistant_knowledge_id');
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }
}
