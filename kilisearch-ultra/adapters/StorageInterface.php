<?php

namespace Kili\Adapters;

interface StorageInterface
{
    /** @return array<int, array<string, mixed>> */
    public function all(): array;

    public function find(string $id): ?array;

    public function save(array $record): array;

    public function delete(string $id): bool;
}
