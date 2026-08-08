<?php

namespace Kili\Core;

/**
 * Detects column types and suggests a mapping onto the universal Kili
 * record model from raw rows (CSV/JSON/DB query results). Heuristic only
 * — header-name aliases first, then value-pattern sniffing (email/phone/
 * url/date/number) as a fallback. No AI involved.
 */
class SchemaDetector
{
    /** @var array<string, string[]> universal field => recognised header aliases */
    private array $fieldAliases;

    public function __construct(array $fieldAliases = [])
    {
        $this->fieldAliases = $fieldAliases ?: self::defaultAliases();
    }

    public static function defaultAliases(): array
    {
        return [
            'title' => ['title', 'name', 'business name', 'company', 'company name'],
            'description' => ['description', 'about', 'summary'],
            'sector' => ['sector', 'industry'],
            'category' => ['category', 'type'],
            'subcategory' => ['subcategory', 'sub category', 'sub-category'],
            'location' => ['location', 'area', 'district', 'neighbourhood', 'neighborhood'],
            'city' => ['city', 'town'],
            'state' => ['state', 'province', 'region'],
            'country' => ['country'],
            'address' => ['address', 'street', 'street address'],
            'phone' => ['phone', 'telephone', 'tel', 'phone number', 'contact', 'contact number'],
            'whatsapp' => ['whatsapp', 'whats app', 'whatsapp number'],
            'email' => ['email', 'e-mail', 'email address'],
            'website' => ['website', 'url', 'web', 'site', 'web address'],
            'latitude' => ['latitude', 'lat'],
            'longitude' => ['longitude', 'lng', 'lon', 'long'],
            'rating' => ['rating', 'score', 'stars'],
            'tags' => ['tags', 'keywords'],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows associative rows (first row's keys define the columns)
     */
    public function detect(array $rows): array
    {
        if (empty($rows)) {
            return ['columns' => [], 'types' => [], 'mapping' => [], 'row_count' => 0];
        }

        $columns = array_keys($rows[0]);
        $types = [];
        $mapping = [];

        foreach ($columns as $column) {
            $type = $this->inferType(array_column($rows, $column));
            $types[$column] = $type;
            $mapping[$column] = $this->suggestField($column, $type);
        }

        return [
            'columns' => $columns,
            'types' => $types,
            'mapping' => $mapping,
            'row_count' => count($rows),
        ];
    }

    /**
     * Transform raw rows into the universal Kili record shape using a
     * (possibly admin-edited) column => field mapping. Unmapped columns
     * (field === null) are dropped.
     */
    public function applyMapping(array $rows, array $mapping): array
    {
        $records = [];
        foreach ($rows as $row) {
            $record = [];
            foreach ($mapping as $column => $field) {
                if ($field === null || $field === '') {
                    continue;
                }
                $value = $row[$column] ?? null;
                if ($field === 'tags' && is_string($value)) {
                    $value = array_values(array_filter(array_map('trim', explode(',', $value))));
                }
                $record[$field] = $value;
            }
            $records[] = $record;
        }

        return $records;
    }

    private function inferType(array $values): string
    {
        $sample = array_slice(array_values(array_filter($values, fn($v) => $v !== null && $v !== '')), 0, 25);
        if (empty($sample)) {
            return 'string';
        }

        $checks = [
            'email' => fn($v) => (bool) preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', (string) $v),
            'url' => fn($v) => (bool) preg_match('/^https?:\/\//i', (string) $v),
            'phone' => fn($v) => (bool) preg_match('/^\+?[0-9][0-9\-\s()]{6,}$/', (string) $v),
            'boolean' => fn($v) => in_array(mb_strtolower((string) $v), ['true', 'false', 'yes', 'no'], true),
            'date' => fn($v) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $v),
            'float' => fn($v) => is_numeric($v) && str_contains((string) $v, '.'),
            'integer' => fn($v) => is_numeric($v) && !str_contains((string) $v, '.'),
        ];

        foreach ($checks as $type => $check) {
            $matches = count(array_filter($sample, $check));
            if ($matches / count($sample) >= 0.8) {
                return $type;
            }
        }

        return 'string';
    }

    private function suggestField(string $column, string $type): ?string
    {
        $normalized = mb_strtolower(trim(preg_replace('/[_\-]+/', ' ', $column)));

        foreach ($this->fieldAliases as $field => $aliases) {
            if (in_array($normalized, $aliases, true)) {
                return $field;
            }
        }

        return match ($type) {
            'email' => 'email',
            'phone' => 'phone',
            'url' => 'website',
            default => null,
        };
    }
}
