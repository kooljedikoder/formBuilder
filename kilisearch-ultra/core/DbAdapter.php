<?php

namespace Kili\Core;

use Kili\Adapters\StorageInterface;

/**
 * The other half of "index & cache" mode: a StorageInterface that queries
 * the live database on every all() call instead of a one-time JSON
 * snapshot, so search results reflect rows added/edited/removed in the
 * source table without an admin re-publishing anything. Results are
 * cached only for the lifetime of the current request (PHP is stateless
 * per-request anyway) — never persisted, which is the whole point.
 *
 * Read-only by default. A source explicitly marked $writable at publish
 * time (an opt-in, since writing to someone's live table is a bigger
 * commitment than reading from it) gets real INSERT/UPDATE/DELETE via
 * ConnectionManager, using the reverse of the column→field mapping to
 * translate a canonical record back into column names. Only fields that
 * *are* mapped to a column get written — anything else in the record is
 * silently not persisted, since there's nowhere in the table for it to
 * go. Writing requires the mapping to include an "id" column; without
 * one there's no reliable way to target a row for UPDATE/DELETE, so
 * save()/delete() throw rather than guessing.
 */
class DbAdapter implements StorageInterface
{
    private ConnectionManager $manager;
    private string $connection;
    private string $table;
    private array $mapping;
    private ?string $sourceId;
    private bool $writable;
    private int $limit;
    private ?array $cache = null;

    public function __construct(ConnectionManager $manager, string $connection, string $table, array $mapping, ?string $sourceId = null, bool $writable = false, int $limit = 2000)
    {
        $this->manager = $manager;
        $this->connection = $connection;
        $this->table = $table;
        $this->mapping = $mapping;
        $this->sourceId = $sourceId;
        $this->writable = $writable;
        $this->limit = $limit;
    }

    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $rows = $this->manager->fetchRows($this->connection, $this->table, $this->limit);
        $detector = new SchemaDetector();
        $mapped = $detector->applyMapping($rows, $this->mapping);

        foreach ($mapped as $i => &$record) {
            $record['id'] = isset($record['id']) ? (string) $record['id'] : (string) ($rows[$i]['id'] ?? $i);
            $record += ['status' => 'active', 'verified' => false, 'tags' => []];
            if ($this->sourceId !== null) {
                $record['source_id'] = $this->sourceId;
            }
        }
        unset($record);

        $this->cache = $mapped;

        return $this->cache;
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
        $this->assertWritable();
        $idColumn = $this->idColumn();
        $columnValues = $this->reverseMap($record);

        if (empty($record['id'])) {
            $newId = $this->manager->insertRow($this->connection, $this->table, $idColumn, $columnValues);
            $record['id'] = $newId;
        } else {
            $this->manager->updateRow($this->connection, $this->table, $idColumn, (string) $record['id'], $columnValues);
        }

        $this->cache = null;

        return $record;
    }

    public function delete(string $id): bool
    {
        $this->assertWritable();
        $idColumn = $this->idColumn();

        $affected = $this->manager->deleteRow($this->connection, $this->table, $idColumn, $id);
        $this->cache = null;

        return $affected > 0;
    }

    private function assertWritable(): void
    {
        if (!$this->writable) {
            throw new \RuntimeException('This is a read-only live database source. An owner-level admin can mark it writable when publishing, or you can edit the row in your database directly.');
        }
    }

    /** @throws \RuntimeException if the mapping has no column mapped to the canonical "id" field */
    private function idColumn(): string
    {
        $reverse = array_flip(array_filter($this->mapping, fn($v) => is_string($v) && $v !== ''));
        if (empty($reverse['id'])) {
            throw new \RuntimeException('This live source has no column mapped to "id", so individual rows can\'t be targeted for update or delete.');
        }

        return $reverse['id'];
    }

    /** Translates a canonical record back to column => value, including only fields that have a mapped column. */
    private function reverseMap(array $record): array
    {
        $reverse = array_flip(array_filter($this->mapping, fn($v) => is_string($v) && $v !== ''));
        $columnValues = [];

        foreach ($record as $field => $value) {
            if ($field === 'id' || !isset($reverse[$field])) {
                continue;
            }
            if (is_array($value)) {
                $value = implode(',', $value);
            } elseif (is_bool($value)) {
                $value = $value ? 1 : 0;
            }
            $columnValues[$reverse[$field]] = $value;
        }

        return $columnValues;
    }
}
