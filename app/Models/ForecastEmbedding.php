<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;

/**
 * A VECTOR EMBEDDING of one historical day, for RAG-based forecasting.
 *
 * Each row is a "document" the forecaster can retrieve: a numeric feature vector
 * plus the outcomes of that day (meals, headcount, expense, cost per meal). To
 * predict a future day we embed the SAME features and fetch the most similar past
 * days by cosine similarity.
 *
 * The vector is stored as JSON rather than a native vector column because the
 * project supports SQLite/MySQL without pgvector; at hundreds-to-thousands of rows
 * per institution an in-PHP cosine scan is instantaneous. The shape is chosen so a
 * future swap to a real ANN index changes only the search, not the data.
 */
class ForecastEmbedding extends Model
{
    use BelongsToInstitution;

    protected $fillable = [
        'institution_id',
        'for_date',
        'embedding',
        'summary',
        'meals',
        'headcount',
        'expense',
        'deposits',
        'cost_per_meal',
        'model',
    ];

    protected $casts = [
        'embedding' => 'array',
        'for_date' => 'date',
        'expense' => 'decimal:2',
        'deposits' => 'decimal:2',
        'cost_per_meal' => 'decimal:4',
    ];

    /**
     * Cosine similarity between two vectors, in [-1, 1].
     *
     * Returns 0.0 when either vector is empty or has zero magnitude, so a
     * degenerate row simply never matches instead of producing a division error.
     */
    public static function cosine(array $a, array $b): float
    {
        $length = min(count($a), count($b));

        if ($length === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;

        for ($i = 0; $i < $length; $i++) {
            $x = (float) $a[$i];
            $y = (float) $b[$i];

            $dot += $x * $y;
            $magA += $x * $x;
            $magB += $y * $y;
        }

        if ($magA <= 0.0 || $magB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($magA) * sqrt($magB));
    }

    /** This row's stored vector. */
    public function vector(): array
    {
        $embedding = $this->embedding;

        return is_array($embedding) ? array_map('floatval', $embedding) : [];
    }
}
