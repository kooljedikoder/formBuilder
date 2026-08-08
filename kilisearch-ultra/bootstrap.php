<?php

require_once __DIR__ . '/adapters/StorageInterface.php';
require_once __DIR__ . '/adapters/JsonAdapter.php';
require_once __DIR__ . '/core/SearchEngine.php';
require_once __DIR__ . '/core/LocationEngine.php';
require_once __DIR__ . '/core/TaxonomyEngine.php';
require_once __DIR__ . '/core/SourceRegistry.php';

use Kili\Adapters\JsonAdapter;
use Kili\Core\SearchEngine;
use Kili\Core\LocationEngine;
use Kili\Core\TaxonomyEngine;
use Kili\Core\SourceRegistry;

/** Reads a JSON config file, returning [] if it doesn't exist or is invalid. */
function kili_read_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode(file_get_contents($path), true);

    return is_array($data) ? $data : [];
}

function kili_storage(): JsonAdapter
{
    static $storage = null;
    if ($storage === null) {
        $storage = new JsonAdapter(__DIR__ . '/data/data.json');
    }

    return $storage;
}

function kili_search_engine(): SearchEngine
{
    static $engine = null;
    if ($engine === null) {
        $searchConfig = kili_read_json(__DIR__ . '/config/search.json');
        $engine = new SearchEngine(kili_storage()->all(), $searchConfig);
    }

    return $engine;
}

function kili_location_engine(): LocationEngine
{
    static $engine = null;
    if ($engine === null) {
        $engine = new LocationEngine(kili_read_json(__DIR__ . '/data/locations.json'));
    }

    return $engine;
}

function kili_taxonomy_engine(): TaxonomyEngine
{
    static $engine = null;
    if ($engine === null) {
        $engine = new TaxonomyEngine(kili_read_json(__DIR__ . '/data/taxonomy.json'));
    }

    return $engine;
}

function kili_source_registry(): SourceRegistry
{
    static $registry = null;
    if ($registry === null) {
        $registry = new SourceRegistry(kili_read_json(__DIR__ . '/data/sources.json'));
    }

    return $registry;
}

function kili_branding(): array
{
    return kili_read_json(__DIR__ . '/config/branding.json');
}

function kili_config(): array
{
    return kili_read_json(__DIR__ . '/config/config.json');
}
