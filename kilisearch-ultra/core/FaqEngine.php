<?php

namespace Kili\Core;

/**
 * "Has this been asked before?" — deterministic token-overlap (Jaccard)
 * similarity against a stored list of canonical questions, no AI/LLM
 * involved. An admin curates data/faq.json; this class only matches
 * against it.
 */
class FaqEngine
{
    private array $entries;
    private float $threshold;

    public function __construct(array $entries, float $threshold = 0.5)
    {
        $this->entries = $entries;
        $this->threshold = $threshold;
    }

    public function match(string $message): ?array
    {
        $tokens = $this->tokenize($message);
        if (empty($tokens)) {
            return null;
        }

        $best = null;
        $bestScore = 0.0;

        foreach ($this->entries as $entry) {
            $score = $this->similarity($tokens, $this->tokenize($entry['question']));
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $entry;
            }
        }

        return ($best !== null && $bestScore >= $this->threshold) ? $best : null;
    }

    private function similarity(array $tokensA, array $tokensB): float
    {
        if (empty($tokensA) || empty($tokensB)) {
            return 0.0;
        }

        $intersection = count(array_intersect($tokensA, $tokensB));
        $union = count(array_unique(array_merge($tokensA, $tokensB)));

        return $union > 0 ? $intersection / $union : 0.0;
    }

    private function tokenize(string $text): array
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^a-z0-9\s]/u', ' ', $text);

        return array_values(array_filter(preg_split('/\s+/', trim($text)) ?: []));
    }
}
