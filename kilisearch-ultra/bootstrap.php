<?php

require_once __DIR__ . '/adapters/StorageInterface.php';
require_once __DIR__ . '/adapters/JsonAdapter.php';
require_once __DIR__ . '/core/SearchEngine.php';
require_once __DIR__ . '/core/LocationEngine.php';
require_once __DIR__ . '/core/TaxonomyEngine.php';
require_once __DIR__ . '/core/SourceRegistry.php';
require_once __DIR__ . '/core/ConversationEngine.php';
require_once __DIR__ . '/core/FormEngine.php';
require_once __DIR__ . '/core/MemoryEngine.php';

use Kili\Adapters\JsonAdapter;
use Kili\Core\SearchEngine;
use Kili\Core\LocationEngine;
use Kili\Core\TaxonomyEngine;
use Kili\Core\SourceRegistry;
use Kili\Core\ConversationEngine;
use Kili\Core\FormEngine;
use Kili\Core\MemoryEngine;

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

/**
 * Reads a free-text query through LocationEngine/TaxonomyEngine and
 * returns soft ranking hints ("mechanic near vi" -> sector=Automotive,
 * category=Vehicle Repair, subcategory=Mechanic, location=Victoria
 * Island). Shared by api/search.php and api/chat.php.
 */
function kili_extract_context(string $query): array
{
    $locationMatch = kili_location_engine()->extractLocation($query);
    $taxonomyMatch = kili_taxonomy_engine()->extractTaxonomy($query);

    return [
        'location' => $locationMatch['name'] ?? null,
        'sector' => $taxonomyMatch['sector'] ?? null,
        'category' => $taxonomyMatch['category'] ?? null,
        'subcategory' => $taxonomyMatch['subcategory'] ?? null,
    ];
}

function kili_resolve_sources(array $records): array
{
    $registry = kili_source_registry();

    return array_map(function ($record) use ($registry) {
        $record['source'] = $registry->resolve($record['source_id'] ?? null);
        return $record;
    }, $records);
}

/** Registers a source in sources.json if it doesn't already exist (e.g. before an import commits records against it). */
function kili_ensure_source(string $id, string $type, string $name): void
{
    $path = __DIR__ . '/data/sources.json';
    $sources = kili_read_json($path);

    foreach ($sources as $source) {
        if ($source['id'] === $id) {
            return;
        }
    }

    $sources[] = ['id' => $id, 'type' => $type, 'name' => $name, 'url' => null, 'last_synced' => gmdate('Y-m-d\TH:i:s\Z')];
    file_put_contents($path, json_encode($sources, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function kili_conversation_engine(): ConversationEngine
{
    static $engine = null;
    if ($engine === null) {
        $engine = new ConversationEngine(kili_read_json(__DIR__ . '/config/conversation.json'));
    }

    return $engine;
}

function kili_submissions_storage(): JsonAdapter
{
    static $storage = null;
    if ($storage === null) {
        $storage = new JsonAdapter(__DIR__ . '/data/submissions.json');
    }

    return $storage;
}

function kili_form_engine(): FormEngine
{
    static $engine = null;
    if ($engine === null) {
        $engine = new FormEngine(kili_read_json(__DIR__ . '/config/forms.json'));
    }

    return $engine;
}

function kili_memory_engine(): MemoryEngine
{
    static $engine = null;
    if ($engine === null) {
        $engine = new MemoryEngine(kili_read_json(__DIR__ . '/data/faq.json'));
    }

    return $engine;
}

/** Bumps a recalled memory entry's hit_count — lets an admin see which stored answers get reused most. */
function kili_memory_record_hit(string $faqId): void
{
    $path = __DIR__ . '/data/faq.json';
    $entries = kili_read_json($path);

    foreach ($entries as &$entry) {
        if ($entry['id'] === $faqId) {
            $entry['hit_count'] = ($entry['hit_count'] ?? 0) + 1;
            break;
        }
    }
    unset($entry);

    file_put_contents($path, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/**
 * Remembers a search query (normalized) so repeated questions become
 * visible — the learning half of the memory engine. An admin reviews
 * frequent entries here and promotes the good ones into data/faq.json
 * with a curated answer; nothing here writes to faq.json automatically.
 */
function kili_memory_remember_query(string $query): void
{
    $path = __DIR__ . '/data/query_log.json';
    $log = kili_read_json($path);
    $normalized = mb_strtolower(trim(preg_replace('/\s+/', ' ', $query)));
    if ($normalized === '') {
        return;
    }

    $found = false;
    foreach ($log as &$entry) {
        if ($entry['normalized'] === $normalized) {
            $entry['count'] = ($entry['count'] ?? 0) + 1;
            $entry['last_asked_at'] = gmdate('Y-m-d\TH:i:s\Z');
            $found = true;
            break;
        }
    }
    unset($entry);

    if (!$found) {
        $log[] = [
            'query' => $query,
            'normalized' => $normalized,
            'count' => 1,
            'last_asked_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    file_put_contents($path, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function kili_branding(): array
{
    return kili_read_json(__DIR__ . '/config/branding.json');
}

function kili_config(): array
{
    return kili_read_json(__DIR__ . '/config/config.json');
}
