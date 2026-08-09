<?php

namespace Killi\Core;

/**
 * Manages which dataset currently backs Search — the "where does Killi's
 * data come from right now" concern. This is the local-file slice of a
 * broader Data Source Engine concept that also includes:
 *  - SchemaDetector + api/import.php: bringing one-off rows (CSV/JSON)
 *    into whichever dataset is active
 *  - (future) real database / API connections, once credentials have a
 *    proper config screen instead of being typed anywhere
 *
 * All three are "data source" concerns; this class only handles
 * choosing between pre-configured local JSON datasets.
 */
class DataSourceEngine
{
    /** @var array<int, array<string, mixed>> */
    private array $sources;
    private ?string $activeId;

    public function __construct(array $config)
    {
        $this->sources = $config['sources'] ?? [];
        $this->activeId = $config['active'] ?? ($this->sources[0]['id'] ?? null);
    }

    public function all(): array
    {
        return $this->sources;
    }

    public function activeId(): ?string
    {
        return $this->activeId;
    }

    public function active(): ?array
    {
        $found = $this->find($this->activeId ?? '');

        return $found ?? ($this->sources[0] ?? null);
    }

    public function find(string $id): ?array
    {
        foreach ($this->sources as $source) {
            if ($source['id'] === $id) {
                return $source;
            }
        }

        return null;
    }
}
