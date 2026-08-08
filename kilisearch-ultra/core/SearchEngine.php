<?php

namespace Kili\Core;

/**
 * KiliSearch core engine: exact / partial / synonym / fuzzy / phonetic
 * matching over the universal Kili record model. Works entirely locally,
 * no external AI API required.
 */
class SearchEngine
{
    /** @var array<int, array<string, mixed>> */
    private array $records;
    private array $synonyms;
    private array $stopWords;
    private array $weights;
    private int $fuzzyMaxDistance;
    private int $fuzzyMinWordLength;

    /** @var array<string, string>|null token => canonical synonym key, built lazily */
    private ?array $synonymIndex = null;

    public function __construct(array $records, array $searchConfig = [])
    {
        $this->records = $records;
        $this->synonyms = $searchConfig['synonyms'] ?? [];
        $this->stopWords = array_flip($searchConfig['stop_words'] ?? []);
        $this->fuzzyMaxDistance = $searchConfig['fuzzy_max_distance'] ?? 2;
        $this->fuzzyMinWordLength = $searchConfig['fuzzy_min_word_length'] ?? 4;
        $this->weights = $searchConfig['weights'] ?? [
            'exact_phrase' => 100,
            'exact_token' => 40,
            'partial_token' => 20,
            'synonym_token' => 25,
            'fuzzy_token' => 15,
            'phonetic_token' => 10,
            'location_bonus' => 15,
            'verified_bonus' => 3,
            'context_location_bonus' => 35,
            'context_sector_bonus' => 20,
            'context_category_bonus' => 30,
            'context_subcategory_bonus' => 35,
        ];
    }

    /**
     * @param array{category?:string,location?:string,sector?:string,verified?:bool} $filters hard filters (chip clicks, explicit params)
     * @param array{location?:?string,sector?:?string,category?:?string,subcategory?:?string} $context soft ranking hints extracted from free text by LocationEngine/TaxonomyEngine
     */
    public function search(string $query, array $filters = [], array $context = [], int $limit = 20, int $offset = 0): array
    {
        $query = trim($query);
        $tokens = $this->tokenize($query);
        $scored = [];

        foreach ($this->records as $record) {
            if (($record['status'] ?? 'active') !== 'active') {
                continue;
            }
            if (!$this->passesFilters($record, $filters)) {
                continue;
            }

            $score = $this->scoreRecord($record, $query, $tokens, $context);

            if ($score > 0) {
                $scored[] = ['record' => $record, 'score' => $score];
            }
        }

        usort($scored, function ($a, $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            return ($b['record']['rating'] ?? 0) <=> ($a['record']['rating'] ?? 0);
        });

        $total = count($scored);
        $page = array_slice($scored, $offset, $limit);

        return [
            'results' => array_map(fn($s) => $s['record'] + ['_score' => $s['score']], $page),
            'total' => $total,
            'query' => $query,
            'tokens' => $tokens,
        ];
    }

    /** Simple prefix autocomplete over titles, categories and tags. */
    public function suggest(string $prefix, int $limit = 5): array
    {
        $prefix = mb_strtolower(trim($prefix));
        if ($prefix === '') {
            return [];
        }

        $suggestions = [];
        foreach ($this->records as $record) {
            foreach (array_merge([$record['title'], $record['category'], $record['subcategory']], $record['tags'] ?? []) as $field) {
                if ($field && str_starts_with(mb_strtolower($field), $prefix)) {
                    $suggestions[mb_strtolower($field)] = $field;
                }
            }
        }

        return array_slice(array_values($suggestions), 0, $limit);
    }

    private function passesFilters(array $record, array $filters): bool
    {
        if (!empty($filters['category']) && mb_strtolower($record['category'] ?? '') !== mb_strtolower($filters['category'])) {
            return false;
        }
        if (!empty($filters['sector']) && mb_strtolower($record['sector'] ?? '') !== mb_strtolower($filters['sector'])) {
            return false;
        }
        if (!empty($filters['location']) && mb_strtolower($record['location'] ?? '') !== mb_strtolower($filters['location'])) {
            return false;
        }
        if (isset($filters['verified']) && (bool) ($record['verified'] ?? false) !== (bool) $filters['verified']) {
            return false;
        }

        return true;
    }

    private function scoreRecord(array $record, string $query, array $tokens, array $context = []): float
    {
        $haystackFields = [
            'title' => $record['title'] ?? '',
            'sector' => $record['sector'] ?? '',
            'category' => $record['category'] ?? '',
            'subcategory' => $record['subcategory'] ?? '',
            'location' => $record['location'] ?? '',
            'description' => $record['description'] ?? '',
            'tags' => implode(' ', $record['tags'] ?? []),
        ];

        $fullHaystack = mb_strtolower(implode(' ', $haystackFields));
        $score = 0.0;

        if ($query !== '' && str_contains($fullHaystack, mb_strtolower($query))) {
            $score += $this->weights['exact_phrase'];
        }

        foreach ($tokens as $token) {
            $score += $this->scoreToken($token, $haystackFields, $fullHaystack);
        }

        $score += $this->scoreContext($record, $context);

        if ($score > 0 && !empty($record['verified'])) {
            $score += $this->weights['verified_bonus'];
        }

        return $score;
    }

    /**
     * Bonus for records that match a location/sector/category/subcategory
     * detected by LocationEngine/TaxonomyEngine from the free-text query
     * (e.g. "vi" resolved to "Victoria Island", or "mechanic" resolved to
     * the Automotive > Vehicle Repair > Mechanic taxonomy path).
     */
    private function scoreContext(array $record, array $context): float
    {
        $score = 0.0;

        if (!empty($context['location']) && mb_strtolower($record['location'] ?? '') === mb_strtolower($context['location'])) {
            $score += $this->weights['context_location_bonus'];
        }
        if (!empty($context['sector']) && mb_strtolower($record['sector'] ?? '') === mb_strtolower($context['sector'])) {
            $score += $this->weights['context_sector_bonus'];
        }
        if (!empty($context['category']) && mb_strtolower($record['category'] ?? '') === mb_strtolower($context['category'])) {
            $score += $this->weights['context_category_bonus'];
        }
        if (!empty($context['subcategory']) && mb_strtolower($record['subcategory'] ?? '') === mb_strtolower($context['subcategory'])) {
            $score += $this->weights['context_subcategory_bonus'];
        }

        return $score;
    }

    private function scoreToken(string $token, array $fields, string $fullHaystack): float
    {
        $tokenLower = mb_strtolower($token);
        $best = 0.0;

        // Exact / partial token match against structured fields.
        foreach (['title', 'sector', 'category', 'subcategory', 'tags'] as $key) {
            $fieldValue = mb_strtolower($fields[$key]);
            $words = preg_split('/\s+/', $fieldValue) ?: [];

            if (in_array($tokenLower, $words, true)) {
                $best = max($best, $this->weights['exact_token']);
            } elseif ($this->isSubstringEligible($tokenLower) && str_contains($fieldValue, $tokenLower)) {
                $best = max($best, $this->weights['partial_token']);
            }
        }

        // Location gets its own bonus so "mechanic lekki" ranks location matches too.
        // Short aliases like "vi" are resolved exactly by LocationEngine's context
        // bonus instead — substring matching them here would false-positive against
        // any field containing that letter pair (e.g. "servicing", "delivery").
        $locationWords = preg_split('/\s+/', mb_strtolower($fields['location'])) ?: [];
        if (in_array($tokenLower, $locationWords, true) || ($this->isSubstringEligible($tokenLower) && str_contains(mb_strtolower($fields['location']), $tokenLower))) {
            $best = max($best, $this->weights['location_bonus']);
        }

        if ($this->isSubstringEligible($tokenLower) && str_contains(mb_strtolower($fields['description']), $tokenLower)) {
            $best = max($best, $this->weights['partial_token']);
        }

        // Synonym expansion: "mekanik"-free exact synonym like "garage" -> mechanic.
        if ($best < $this->weights['exact_token'] && $this->matchesSynonym($tokenLower, $fullHaystack)) {
            $best = max($best, $this->weights['synonym_token']);
        }

        // Fuzzy typo correction (Levenshtein) for longer words only.
        if ($best === 0.0 && mb_strlen($tokenLower) >= $this->fuzzyMinWordLength) {
            $fuzzyScore = $this->fuzzyScore($tokenLower, $fullHaystack);
            $best = max($best, $fuzzyScore);
        }

        // Phonetic (Soundex) fallback for short/typo'd words fuzzy missed.
        if ($best === 0.0) {
            $phoneticScore = $this->phoneticScore($tokenLower, $fullHaystack);
            $best = max($best, $phoneticScore);
        }

        return $best;
    }

    /** Substring ("contains") matching is only meaningful for tokens of 3+ chars — shorter tokens match too much noise. */
    private function isSubstringEligible(string $token): bool
    {
        return mb_strlen($token) >= 3;
    }

    private function matchesSynonym(string $token, string $fullHaystack): bool
    {
        $index = $this->buildSynonymIndex();

        if (isset($index[$token]) && str_contains($fullHaystack, $index[$token])) {
            return true;
        }

        return false;
    }

    private function buildSynonymIndex(): array
    {
        if ($this->synonymIndex !== null) {
            return $this->synonymIndex;
        }

        $index = [];
        foreach ($this->synonyms as $canonical => $aliases) {
            $index[mb_strtolower($canonical)] = mb_strtolower($canonical);
            foreach ($aliases as $alias) {
                foreach (preg_split('/\s+/', mb_strtolower($alias)) as $word) {
                    $index[$word] = mb_strtolower($canonical);
                }
            }
        }

        return $this->synonymIndex = $index;
    }

    private function fuzzyScore(string $token, string $fullHaystack): float
    {
        $best = 0.0;
        foreach (preg_split('/\s+/', $fullHaystack) as $word) {
            $word = trim($word, " .,");
            if ($word === '' || abs(mb_strlen($word) - mb_strlen($token)) > $this->fuzzyMaxDistance) {
                continue;
            }

            $distance = levenshtein($token, $word);
            if ($distance <= $this->fuzzyMaxDistance) {
                // Closer matches score higher, scaled by the fuzzy weight.
                $ratio = 1 - ($distance / max(mb_strlen($token), mb_strlen($word)));
                $best = max($best, $this->weights['fuzzy_token'] * $ratio);
            }
        }

        return $best;
    }

    private function phoneticScore(string $token, string $fullHaystack): float
    {
        if (mb_strlen($token) < 3 || !ctype_alpha($token)) {
            return 0.0;
        }

        $tokenCode = soundex($token);
        foreach (preg_split('/\s+/', $fullHaystack) as $word) {
            $word = trim($word, " .,");
            if ($word !== '' && ctype_alpha($word) && soundex($word) === $tokenCode) {
                return $this->weights['phonetic_token'];
            }
        }

        return 0.0;
    }

    private function tokenize(string $query): array
    {
        $query = mb_strtolower($query);
        $query = preg_replace('/[^a-z0-9\s]/u', ' ', $query);
        $words = preg_split('/\s+/', trim($query)) ?: [];

        return array_values(array_filter($words, fn($w) => $w !== '' && !isset($this->stopWords[$w])));
    }
}
