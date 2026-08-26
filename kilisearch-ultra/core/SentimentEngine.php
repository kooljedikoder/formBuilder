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

    public function __construct(array $lexicon)
    {
        $this->positive = array_flip($lexicon['positive'] ?? []);
        $this->negative = array_flip($lexicon['negative'] ?? []);
        $this->negators = array_flip($lexicon['negators'] ?? []);
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
            if (isset($this->negators[$token])) {
                $negateNext = true;
                continue;
            }

            $polarity = 0;
            if (isset($this->positive[$token])) {
                $polarity = 1;
            } elseif (isset($this->negative[$token])) {
                $polarity = -1;
            }

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
}
