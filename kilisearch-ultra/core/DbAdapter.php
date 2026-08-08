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
 * Deliberately read-only: save()/delete() throw rather than attempting a
 * generic reverse-mapped UPDATE/DELETE against an arbitrary table schema,
 * which would be a materially larger and riskier feature (safe column
 * reverse-mapping, transactions, conflict handling) than "make search see
 * live data." CRUD against a live source isn't supported this phase —
 * publish a cached copy (the existing index & cache flow) to edit rows
 * through Kili instead.
 */
class DbAdapter implements StorageInterface
{
    private ConnectionManager $manager;
    private string $connection;
    private string $table;
    private array $mapping;
    private ?string $sourceId;
    private int $limit;
    private ?array $cache = null;

    public function __construct(ConnectionManager $manager, string $connection, string $table, array $mapping, ?string $sourceId = null, int $limit = 2000)
    {
        $this->manager = $manager;
        $this->connection = $connection;
        $this->table = $table;
        $this->mapping = $mapping;
        $this->sourceId = $sourceId;
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
        throw new \RuntimeException('This is a live database source — it\'s read-only here. Edit the row in your database directly, or publish a cached copy of this table to enable editing through Kili.');
    }

    public function delete(string $id): bool
    {
        throw new \RuntimeException('This is a live database source — it\'s read-only here. Edit the row in your database directly, or publish a cached copy of this table to enable editing through Kili.');
    }
}
