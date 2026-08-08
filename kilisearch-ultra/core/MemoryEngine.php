<?php

namespace Kili\Core;

/**
 * Kili's third pillar alongside SearchEngine and ConversationEngine:
 * "has this been asked before?" Matches new questions against a curated
 * list of Q&A (data/faq.json) using deterministic token-overlap (Jaccard)
 * similarity — no AI/LLM. Remembering *new* questions (so repeated ones
 * become visible for an admin to promote into a curated answer) is
 * handled by the kili_memory_remember_query() helper in bootstrap.php,
 * which writes to data/query_log.json — kept separate from this class
 * only because it's pure file I/O, not matching logic.
 */
class MemoryEngine
{
    private array $entries;
    private float $threshold;

    public function __construct(array $entries, float $threshold = 0.5)
    {
        $this->entries = $entries;
        $this->threshold = $threshold;
    }

    /** Recalls a previously curated answer for a question similar enough to this message, or null. */
    public function recall(string $message): ?array
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
