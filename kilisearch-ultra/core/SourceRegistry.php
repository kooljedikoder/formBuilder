<?php

namespace Killi\Core;

/**
 * Every result needs to know where it came from. Records store a
 * source_id; this registry resolves it to the full source record
 * (type, name, url, last_synced) so the UI/admin can show provenance.
 */
class SourceRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $sources = [];

    public function __construct(array $sources)
    {
        foreach ($sources as $source) {
            $this->sources[$source['id']] = $source;
        }
    }

    public function resolve(?string $sourceId): ?array
    {
        return $sourceId !== null ? ($this->sources[$sourceId] ?? null) : null;
    }

    public function all(): array
    {
        return array_values($this->sources);
    }
}
