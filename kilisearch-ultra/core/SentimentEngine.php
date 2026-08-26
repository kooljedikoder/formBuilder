<?php

namespace Killi\Core;

/**
 * Small offline lexicon-based sentiment scorer — no ML runtime, no
 * external API call, same deterministic philosophy as ConversationEngine
 * and the taxonomy/location alias matching elsewhere in this project.
 * Good enough to catch "this is useless" or "thanks, that was quick" —
 * not a replacement for a real sentiment model, just enough signal to
 * soften a reply when someone sounds frustrated.
 */
class SentimentEngine
{
    /** @var array<string, true> */
    private array $positive;
    /** @var array<string, true> */
    private array $negative;
    /** @var array<string, true> */
    private array $negators;
    private int $fuzzyMaxDistance;
    private int $fuzzyMinWordLength;

    /** @var array<string, string>|null lexicon word => 'positive'|'negative'|'negator', built lazily */
    private ?array $lexiconIndex = null;

    public function __construct(array $lexicon)
    {
        $this->positive = array_flip($lexicon['positive'] ?? []);
        $this->negative = array_flip($lexicon['negative'] ?? []);
        $this->negators = array_flip($lexicon['negators'] ?? []);
        $this->fuzzyMaxDistance = $lexicon['fuzzy_max_distance'] ?? 1;
        $this->fuzzyMinWordLength = $lexicon['fuzzy_min_word_length'] ?? 3;
    }

    /**
     * @return array{score:int,label:string} label is 'positive'|'neutral'|'negative'
     */
    public function analyze(string $text): array
    {
        $tokens = preg_split('/[^a-z\']+/', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $score = 0;
        $negateNext = false;

        foreach ($tokens as $token) {
            $category = $this->classify($token);

            if ($category === 'negator') {
                $negateNext = true;
                continue;
            }

            $polarity = $category === 'positive' ? 1 : ($category === 'negative' ? -1 : 0);

            if ($polarity !== 0) {
                // "not good" flips the very next polarity word; anything
                // else in between (rare for short chat messages) just
                // resets the flag rather than chaining further negation.
                $score += $negateNext ? -$polarity : $polarity;
                $negateNext = false;
            }
        }

        $label = $score <= -1 ? 'negative' : ($score >= 1 ? 'positive' : 'neutral');

        return ['score' => $score, 'label' => $label];
    }

    /**
     * Exact match first — a single typo ("niot" for "not") shouldn't make
     * the whole message read as neutral, so unmatched words fall back to
     * the same fuzzy (Levenshtein) + phonetic (Soundex) technique
     * SearchEngine already uses for typo'd search terms.
     */
    private function classify(string $token): ?string
    {
        if (isset($this->negators[$token])) {
            return 'negator';
        }
        if (isset($this->positive[$token])) {
            return 'positive';
        }
        if (isset($this->negative[$token])) {
            return 'negative';
        }

        if (mb_strlen($token) < $this->fuzzyMinWordLength || !ctype_alpha($token)) {
            return null;
        }

        $index = $this->buildLexiconIndex();

        foreach ($index as $word => $category) {
            if (abs(mb_strlen($word) - mb_strlen($token)) <= $this->fuzzyMaxDistance
                && levenshtein($token, $word) <= $this->fuzzyMaxDistance) {
                return $category;
            }
        }

        $tokenCode = soundex($token);
        foreach ($index as $word => $category) {
            if (soundex($word) === $tokenCode) {
                return $category;
            }
        }

        return null;
    }

    private function buildLexiconIndex(): array
    {
        if ($this->lexiconIndex !== null) {
            return $this->lexiconIndex;
        }

        $index = [];
        foreach (array_keys($this->negators) as $word) {
            $index[$word] = 'negator';
        }
        foreach (array_keys($this->positive) as $word) {
            $index[$word] = 'positive';
        }
        foreach (array_keys($this->negative) as $word) {
            $index[$word] = 'negative';
        }

        return $this->lexiconIndex = $index;
    }
}
