<?php

namespace Killi\Core;

/**
 * Universal Sector > Category > Subcategory taxonomy so Killi isn't
 * hard-coded to "business directory" — the same tree works for any
 * vertical (Automotive, Healthcare, Hospitality, ...).
 */
class TaxonomyEngine
{
    private array $tree;
    /** @var array<string, array{sector:string,category:?string,subcategory:?string}> lowercase phrase => match */
    private array $index = [];

    public function __construct(array $tree)
    {
        $this->tree = $tree;
        $this->buildIndex();
    }

    private function buildIndex(): void
    {
        foreach ($this->tree as $sectorNode) {
            $sector = $sectorNode['sector'];
            $sectorEntry = ['sector' => $sector, 'category' => null, 'subcategory' => null];
            $this->index[mb_strtolower($sector)] = $sectorEntry;
            // Aliases are everyday words for a sector/category that never
            // appear in the taxonomy names themselves — "cars" for
            // Automotive, "clinic" for Healthcare — same mechanism
            // LocationEngine already uses for area aliases like "vi".
            foreach ($sectorNode['aliases'] ?? [] as $alias) {
                $this->index[mb_strtolower($alias)] = $sectorEntry;
            }

            foreach ($sectorNode['categories'] ?? [] as $categoryNode) {
                $category = $categoryNode['category'];
                $categoryEntry = ['sector' => $sector, 'category' => $category, 'subcategory' => null];
                $this->index[mb_strtolower($category)] = $categoryEntry;
                foreach ($categoryNode['aliases'] ?? [] as $alias) {
                    $this->index[mb_strtolower($alias)] = $categoryEntry;
                }

                foreach ($categoryNode['subcategories'] ?? [] as $subcategory) {
                    $this->index[mb_strtolower($subcategory)] = [
                        'sector' => $sector,
                        'category' => $category,
                        'subcategory' => $subcategory,
                    ];
                }
            }
        }
    }

    /**
     * Longest-phrase match of a known sector/category/subcategory name inside free text.
     * e.g. "mechanic in lekki" -> sector=Automotive, category=Vehicle Repair, subcategory=Mechanic
     */
    public function extractTaxonomy(string $query): ?array
    {
        // Strip punctuation with a Unicode letter/number class (not a-z0-9)
        // so it doesn't matter that this runs before mb_strtolower() below —
        // an [^a-z0-9] class here would silently drop every capital letter
        // first, breaking any query typed with capitals at all.
        $normalized = ' ' . mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $query)) . ' ';

        $matched = null;
        $matchedPhrase = '';
        foreach ($this->index as $phrase => $entry) {
            if (str_contains($normalized, ' ' . $phrase . ' ') && mb_strlen($phrase) > mb_strlen($matchedPhrase)) {
                $matched = $entry;
                $matchedPhrase = $phrase;
            }
        }

        return $matched === null ? null : $matched + ['matched_phrase' => $matchedPhrase];
    }

    public function tree(): array
    {
        return $this->tree;
    }
}
