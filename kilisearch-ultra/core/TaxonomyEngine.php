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
            $this->index[mb_strtolower($sector)] = ['sector' => $sector, 'category' => null, 'subcategory' => null];

            foreach ($sectorNode['categories'] ?? [] as $categoryNode) {
                $category = $categoryNode['category'];
                $this->index[mb_strtolower($category)] = ['sector' => $sector, 'category' => $category, 'subcategory' => null];

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
        $normalized = ' ' . mb_strtolower(preg_replace('/[^a-z0-9\s]/u', ' ', $query)) . ' ';

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
