<?php

namespace Kili\Core;

use Kili\Adapters\JsonAdapter;

/**
 * The fourth pillar alongside Search, Conversational and Memory: owning
 * the data, not just reading it. Wraps a data source's JsonAdapter (which
 * already does create/update/delete at the storage layer) with the parts
 * a real admin tool needs on top — validation, pagination/search over the
 * listing, and stamping every record with which source it belongs to.
 */
class CrudEngine
{
    private JsonAdapter $storage;
    private string $sourceId;

    public function __construct(JsonAdapter $storage, string $sourceId)
    {
        $this->storage = $storage;
        $this->sourceId = $sourceId;
    }

    /** @return array{records: array<int, array<string, mixed>>, total: int, page: int, perPage: int} */
    public function list(string $query = '', int $page = 1, int $perPage = 20): array
    {
        $records = $this->storage->all();

        if ($query !== '') {
            $needle = mb_strtolower($query);
            $records = array_values(array_filter($records, function ($record) use ($needle) {
                $haystack = mb_strtolower(($record['title'] ?? '') . ' ' . ($record['sector'] ?? '') . ' ' . ($record['category'] ?? '') . ' ' . implode(' ', $record['tags'] ?? []));
                return str_contains($haystack, $needle);
            }));
        }

        usort($records, fn($a, $b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));

        $total = count($records);
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        return [
            'records' => array_slice($records, $offset, $perPage),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
        ];
    }

    public function get(string $id): ?array
    {
        return $this->storage->find($id);
    }

    /** @throws \InvalidArgumentException on validation failure */
    public function create(array $data): array
    {
        $errors = $this->validate($data);
        if ($errors) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }

        unset($data['id']);
        $data += ['status' => 'active', 'verified' => false, 'tags' => []];
        $data['source_id'] = $this->sourceId;

        return $this->storage->save($data);
    }

    /** @throws \InvalidArgumentException on validation failure or unknown id */
    public function update(string $id, array $data): array
    {
        if ($this->storage->find($id) === null) {
            throw new \InvalidArgumentException("No record with id \"$id\" in this source.");
        }

        $data['id'] = $id;
        $errors = $this->validate($data, true);
        if ($errors) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }

        $data['source_id'] = $this->sourceId;

        return $this->storage->save($data);
    }

    public function delete(string $id): bool
    {
        return $this->storage->delete($id);
    }

    /** @return string[] validation error messages, empty when valid */
    private function validate(array $data, bool $isUpdate = false): array
    {
        $errors = [];

        if (trim((string) ($data['title'] ?? '')) === '') {
            $errors[] = 'Title is required.';
        }

        if (isset($data['email']) && $data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email address is not valid.';
        }

        if (isset($data['status']) && !in_array($data['status'], ['active', 'inactive'], true)) {
            $errors[] = 'Status must be "active" or "inactive".';
        }

        return $errors;
    }
}
