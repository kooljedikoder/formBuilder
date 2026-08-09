<?php

namespace Killi\Core;

/**
 * Country > State > City > Area hierarchy with aliases, plus distance
 * calculation for "near me" style queries. Location is a first-class
 * foundation piece, not a bolt-on filter.
 */
class LocationEngine
{
    private array $tree;
    /** @var array<string, array{name:string,country:string,state:string,city:string,latitude:?float,longitude:?float}> */
    private array $areaIndex = [];

    public function __construct(array $tree)
    {
        $this->tree = $tree;
        $this->buildIndex();
    }

    private function buildIndex(): void
    {
        foreach ($this->tree as $country) {
            foreach ($country['states'] ?? [] as $state) {
                foreach ($state['cities'] ?? [] as $city) {
                    foreach ($city['areas'] ?? [] as $area) {
                        $entry = [
                            'name' => $area['name'],
                            'country' => $country['country'],
                            'state' => $state['state'],
                            'city' => $city['city'],
                            'latitude' => $area['latitude'] ?? null,
                            'longitude' => $area['longitude'] ?? null,
                        ];

                        $this->areaIndex[mb_strtolower($area['name'])] = $entry;
                        foreach ($area['aliases'] ?? [] as $alias) {
                            $this->areaIndex[mb_strtolower($alias)] = $entry;
                        }
                    }
                }
            }
        }
    }

    /**
     * Longest-phrase match of a known area name/alias inside free text.
     * e.g. "restaurants near vi" -> Victoria Island (via the "vi" alias)
     */
    public function extractLocation(string $query): ?array
    {
        $normalized = ' ' . mb_strtolower(preg_replace('/[^a-z0-9\s]/u', ' ', $query)) . ' ';

        $matched = null;
        $matchedPhrase = '';
        foreach ($this->areaIndex as $phrase => $entry) {
            if (str_contains($normalized, ' ' . $phrase . ' ') && mb_strlen($phrase) > mb_strlen($matchedPhrase)) {
                $matched = $entry;
                $matchedPhrase = $phrase;
            }
        }

        return $matched === null ? null : $matched + ['matched_phrase' => $matchedPhrase];
    }

    public function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function tree(): array
    {
        return $this->tree;
    }
}
