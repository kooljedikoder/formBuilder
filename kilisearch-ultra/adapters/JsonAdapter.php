<?php

namespace Killi\Adapters;

/**
 * Zero-DB storage adapter. Records live in a single JSON file so the
 * whole product can run on shared PHP hosting with no database.
 */
class JsonAdapter implements StorageInterface
{
    private string $path;
    /** @var array<int, array<string, mixed>>|null */
    private ?array $records = null;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function all(): array
    {
        if ($this->records === null) {
            $this->records = $this->load();
        }

        return $this->records;
    }

    public function find(string $id): ?array
    {
        foreach ($this->all() as $record) {
            if ((string) $record['id'] === $id) {
                return $record;
            }
        }

        return null;
    }

    public function save(array $record): array
    {
        $records = $this->all();
        $record['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');

        if (empty($record['id'])) {
            $maxId = 0;
            foreach ($records as $r) {
                $maxId = max($maxId, (int) $r['id']);
            }
            $record['id'] = (string) ($maxId + 1);
            $record['created_at'] = $record['updated_at'];
            $records[] = $record;
        } else {
            $found = false;
            foreach ($records as $i => $r) {
                if ((string) $r['id'] === (string) $record['id']) {
                    $records[$i] = array_merge($r, $record);
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $records[] = $record;
            }
        }

        $this->records = $records;
        $this->persist();

        return $record;
    }

    public function delete(string $id): bool
    {
        $records = $this->all();
        $before = count($records);
        $records = array_values(array_filter($records, fn($r) => (string) $r['id'] !== $id));
        $this->records = $records;
        $this->persist();

        return count($records) < $before;
    }

    private function load(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $json = file_get_contents($this->path);
        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    private function persist(): void
    {
        file_put_contents(
            $this->path,
            json_encode($this->records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
