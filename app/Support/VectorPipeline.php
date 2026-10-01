<?php

namespace App\Support;

/**
 * VECTOR EMBEDDING PIPELINE & SIMILARITY SEARCH.
 *
 * Produces deterministic 64-dimensional dense normalized embeddings from arbitrary text
 * using character n-grams and hashed semantic projection (Random Projection / LSH approach).
 * Also provides Cosine Similarity calculations for retrieval-augmented generation (RAG).
 */
class VectorPipeline
{
    public const DIMENSIONS = 64;

    /**
     * Generate a normalized 64-dimensional float vector for text.
     *
     * @return array<float>
     */
    public static function embed(string $text): array
    {
        $cleaned = mb_strtolower(trim($text));
        $vector = array_fill(0, self::DIMENSIONS, 0.0);

        if ($cleaned === '') {
            return $vector;
        }

        // Tokenize words and character 3-grams
        $words = preg_split('/[\s\p{P}]+/u', $cleaned, -1, PREG_SPLIT_NO_EMPTY);
        $features = [];

        foreach ($words as $word) {
            $features[] = $word;
            $len = mb_strlen($word);
            if ($len >= 3) {
                for ($i = 0; $i <= $len - 3; $i++) {
                    $features[] = mb_substr($word, $i, 3);
                }
            }
        }

        foreach ($features as $feature) {
            $h1 = crc32($feature);
            $idx = abs($h1) % self::DIMENSIONS;
            $sign = ($h1 & 1) ? 1.0 : -1.0;
            $vector[$idx] += $sign;

            // Secondary hash projection for feature dispersion
            $h2 = crc32(strrev($feature));
            $idx2 = abs($h2) % self::DIMENSIONS;
            $sign2 = ($h2 & 1) ? 0.7 : -0.7;
            $vector[$idx2] += $sign2;
        }

        // L2 Normalize
        $norm = 0.0;
        foreach ($vector as $val) {
            $norm += $val * $val;
        }
        $norm = sqrt($norm);

        if ($norm > 0) {
            for ($i = 0; $i < self::DIMENSIONS; $i++) {
                $vector[$i] = round($vector[$i] / $norm, 6);
            }
        }

        return $vector;
    }

    /**
     * Compute Cosine Similarity between two dense float vectors.
     * Returns a float between -1.0 and 1.0.
     *
     * @param  array<float>  $vecA
     * @param  array<float>  $vecB
     */
    public static function cosineSimilarity(array $vecA, array $vecB): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        $dims = min(count($vecA), count($vecB));
        for ($i = 0; $i < $dims; $i++) {
            $a = (float) ($vecA[$i] ?? 0.0);
            $b = (float) ($vecB[$i] ?? 0.0);
            $dot += $a * $b;
            $normA += $a * $a;
            $normB += $b * $b;
        }

        $denom = sqrt($normA) * sqrt($normB);
        if ($denom <= 0.0) {
            return 0.0;
        }

        return max(-1.0, min(1.0, $dot / $denom));
    }
}
