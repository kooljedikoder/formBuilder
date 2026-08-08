<?php

namespace Kili\Core;

/**
 * Manages named remote-database connection profiles loaded from .env
 * (never from config/data_sources.json, never sent to the browser).
 * Supports MySQL and Postgres via PDO. Backs both "index & cache" mode
 * (fetchRows() through the schema detector into a local JSON snapshot)
 * and live-query mode (DbAdapter calls fetchRows() fresh per search, and
 * — for a source explicitly marked writable — insertRow()/updateRow()/
 * deleteRow() for CRUD directly against the live table).
 */
class ConnectionManager
{
    private array $env;

    public function __construct(array $env)
    {
        $this->env = $env;
    }

    public function profileNames(): array
    {
        $raw = $this->env['KILI_DB_PROFILES'] ?? '';

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /** Safe to send to a browser — no password. */
    public function profile(string $name): ?array
    {
        $full = $this->credentials($name);
        if ($full === null) {
            return null;
        }

        $hasPassword = $full['password'] !== '';
        unset($full['password']);
        $full['has_password'] = $hasPassword;

        return $full;
    }

    /** Includes the password — for internal connection use only, never returned via an API response. */
    public function credentials(string $name): ?array
    {
        if (!in_array($name, $this->profileNames(), true)) {
            return null;
        }

        return [
            'name' => $name,
            'driver' => $this->env[$this->key($name, 'DRIVER')] ?? 'mysql',
            'host' => $this->env[$this->key($name, 'HOST')] ?? '',
            'port' => $this->env[$this->key($name, 'PORT')] ?? '',
            'database' => $this->env[$this->key($name, 'DATABASE')] ?? '',
            'username' => $this->env[$this->key($name, 'USERNAME')] ?? '',
            'password' => $this->env[$this->key($name, 'PASSWORD')] ?? '',
            'ssl' => filter_var($this->env[$this->key($name, 'SSL')] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    private function key(string $name, string $suffix): string
    {
        return 'KILI_DB_' . strtoupper($name) . '_' . $suffix;
    }

    public function buildDsn(array $profile): string
    {
        $driver = $profile['driver'] === 'postgres' ? 'pgsql' : $profile['driver'];

        return sprintf('%s:host=%s;port=%s;dbname=%s', $driver, $profile['host'], $profile['port'], $profile['database']);
    }

    public function connect(string $name): \PDO
    {
        $profile = $this->credentials($name);
        if ($profile === null) {
            throw new \RuntimeException("Unknown connection profile \"$name\"");
        }

        return new \PDO($this->buildDsn($profile), $profile['username'], $profile['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    public function testConnection(string $name): array
    {
        try {
            $this->connect($name)->query('SELECT 1');

            return ['success' => true, 'message' => 'Connected successfully.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function listTables(string $name): array
    {
        $profile = $this->credentials($name);
        $pdo = $this->connect($name);

        $stmt = ($profile['driver'] ?? '') === 'postgres'
            ? $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name")
            : $pdo->query('SHOW TABLES');

        return array_map(fn($row) => array_values($row)[0], $stmt->fetchAll());
    }

    /**
     * @throws \InvalidArgumentException if $table isn't a plain identifier
     */
    public function fetchRows(string $name, string $table, int $limit = 200): array
    {
        $this->assertIdentifier($table, 'table name');

        $pdo = $this->connect($name);
        $stmt = $pdo->prepare('SELECT * FROM ' . $table . ' LIMIT :limit');
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Only a plain [A-Za-z_][A-Za-z0-9_]* identifier passes — table and
     * column names can't be parameterized in PDO (only values can), so
     * every one that ends up concatenated into a SQL string is checked
     * here first, every time, rather than trusted from stored config.
     *
     * @throws \InvalidArgumentException
     */
    private function assertIdentifier(string $identifier, string $what): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException("Invalid $what: \"$identifier\".");
        }
    }

    /**
     * Inserts a row and returns the new primary key as a string.
     * @throws \InvalidArgumentException on an invalid identifier or empty $columnValues
     */
    public function insertRow(string $name, string $table, string $idColumn, array $columnValues): string
    {
        $this->assertIdentifier($table, 'table name');
        $this->assertIdentifier($idColumn, 'id column name');
        if (empty($columnValues)) {
            throw new \InvalidArgumentException('No mapped columns to insert.');
        }
        foreach (array_keys($columnValues) as $column) {
            $this->assertIdentifier($column, 'column name');
        }

        $profile = $this->credentials($name);
        $pdo = $this->connect($name);
        $columns = array_keys($columnValues);
        $placeholders = array_map(fn($c) => ':' . $c, $columns);

        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        if (($profile['driver'] ?? '') === 'postgres') {
            $sql .= ' RETURNING ' . $idColumn;
        }

        $stmt = $pdo->prepare($sql);
        foreach ($columnValues as $column => $value) {
            $stmt->bindValue(':' . $column, $value);
        }
        $stmt->execute();

        if (($profile['driver'] ?? '') === 'postgres') {
            return (string) $stmt->fetchColumn();
        }

        return (string) $pdo->lastInsertId();
    }

    /**
     * @throws \InvalidArgumentException on an invalid identifier or empty $columnValues
     */
    public function updateRow(string $name, string $table, string $idColumn, string $idValue, array $columnValues): int
    {
        $this->assertIdentifier($table, 'table name');
        $this->assertIdentifier($idColumn, 'id column name');
        if (empty($columnValues)) {
            throw new \InvalidArgumentException('No mapped columns to update.');
        }
        foreach (array_keys($columnValues) as $column) {
            $this->assertIdentifier($column, 'column name');
        }

        $pdo = $this->connect($name);
        $assignments = array_map(fn($c) => $c . ' = :' . $c, array_keys($columnValues));
        $stmt = $pdo->prepare('UPDATE ' . $table . ' SET ' . implode(', ', $assignments) . ' WHERE ' . $idColumn . ' = :__id');
        foreach ($columnValues as $column => $value) {
            $stmt->bindValue(':' . $column, $value);
        }
        $stmt->bindValue(':__id', $idValue);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * @throws \InvalidArgumentException on an invalid identifier
     */
    public function deleteRow(string $name, string $table, string $idColumn, string $idValue): int
    {
        $this->assertIdentifier($table, 'table name');
        $this->assertIdentifier($idColumn, 'id column name');

        $pdo = $this->connect($name);
        $stmt = $pdo->prepare('DELETE FROM ' . $table . ' WHERE ' . $idColumn . ' = :__id');
        $stmt->bindValue(':__id', $idValue);
        $stmt->execute();

        return $stmt->rowCount();
    }
}
