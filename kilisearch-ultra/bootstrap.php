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
require_once __DIR__ . '/core/DataSourceEngine.php';
require_once __DIR__ . '/core/SchemaDetector.php';
require_once __DIR__ . '/core/ConnectionManager.php';

use Kili\Adapters\JsonAdapter;
use Kili\Core\SearchEngine;
use Kili\Core\LocationEngine;
use Kili\Core\TaxonomyEngine;
use Kili\Core\SourceRegistry;
use Kili\Core\ConversationEngine;
use Kili\Core\FormEngine;
use Kili\Core\MemoryEngine;
use Kili\Core\DataSourceEngine;
use Kili\Core\SchemaDetector;
use Kili\Core\ConnectionManager;

/** Reads a JSON config file, returning [] if it doesn't exist or is invalid. */
function kili_read_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode(file_get_contents($path), true);

    return is_array($data) ? $data : [];
}

/** Simple KEY=VALUE .env parser. Credentials live only here, never in config/*.json, never echoed back by an API. */
function kili_load_env(): array
{
    static $env = null;
    if ($env === null) {
        $env = [];
        $path = __DIR__ . '/.env';
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $env[trim($key)] = trim($value);
            }
        }
    }

    return $env;
}

function kili_connection_manager(): ConnectionManager
{
    static $manager = null;
    if ($manager === null) {
        $manager = new ConnectionManager(kili_load_env());
    }

    return $manager;
}

/**
 * Writes/updates one connection profile's keys in .env, preserving
 * everything else in the file. The password field is only written when
 * provided (an empty password field means "keep the existing one" on
 * update) and is never read back out by any API response.
 */
function kili_save_env_profile(string $name, array $fields): void
{
    $path = __DIR__ . '/.env';
    $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];

    $existingKeys = [];
    foreach ($lines as $i => $line) {
        if (preg_match('/^([A-Z0-9_]+)=/', trim($line), $m)) {
            $existingKeys[$m[1]] = $i;
        }
    }

    $upper = strtoupper($name);
    $updates = [
        'KILI_DB_' . $upper . '_DRIVER' => $fields['driver'],
        'KILI_DB_' . $upper . '_HOST' => $fields['host'],
        'KILI_DB_' . $upper . '_PORT' => $fields['port'],
        'KILI_DB_' . $upper . '_DATABASE' => $fields['database'],
        'KILI_DB_' . $upper . '_USERNAME' => $fields['username'],
        'KILI_DB_' . $upper . '_SSL' => !empty($fields['ssl']) ? 'true' : 'false',
    ];
    if (!empty($fields['password'])) {
        $updates['KILI_DB_' . $upper . '_PASSWORD'] = $fields['password'];
    }

    foreach ($updates as $key => $value) {
        $line = $key . '=' . $value;
        if (isset($existingKeys[$key])) {
            $lines[$existingKeys[$key]] = $line;
        } else {
            $lines[] = $line;
        }
    }

    $profilesKey = 'KILI_DB_PROFILES';
    $existingProfiles = [];
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), $profilesKey . '=')) {
            $existingProfiles = array_values(array_filter(array_map('trim', explode(',', substr(trim($line), strlen($profilesKey) + 1)))));
            break;
        }
    }
    if (!in_array($name, $existingProfiles, true)) {
        $existingProfiles[] = $name;
    }

    $profilesLine = $profilesKey . '=' . implode(',', $existingProfiles);
    if (isset($existingKeys[$profilesKey])) {
        $lines[$existingKeys[$profilesKey]] = $profilesLine;
    } else {
        array_unshift($lines, $profilesLine);
    }

    file_put_contents($path, implode("\n", $lines) . "\n", LOCK_EX);
}

/**
 * Writes/replaces a single top-level .env key (e.g. a password hash),
 * preserving everything else in the file.
 */
function kili_save_env_value(string $key, string $value): void
{
    $path = __DIR__ . '/.env';
    $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];

    $found = false;
    foreach ($lines as $i => $line) {
        if (preg_match('/^' . preg_quote($key, '/') . '=/', trim($line))) {
            $lines[$i] = $key . '=' . $value;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $lines[] = $key . '=' . $value;
    }

    file_put_contents($path, implode("\n", $lines) . "\n", LOCK_EX);
}

function kili_ensure_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

/**
 * Two separate credentials, deliberately different defaults:
 *  - Admin is ALWAYS gated. No configured password means "not set up
 *    yet," not "wide open" — admin/connections.php shows a mandatory
 *    one-time setup form instead of the connections UI until one exists.
 *  - The app-wide password is OPTIONAL and off by default, so the
 *    customer-facing app stays zero-friction/"plug and play" out of the
 *    box. An admin can turn it on for a protected deployment.
 * This gates *access* to the app/admin, not individual features within
 * them — consistent with "no artificial feature-lock passwords."
 */
function kili_app_password_configured(): bool
{
    return !empty(kili_load_env()['KILI_APP_PASSWORD_HASH'] ?? '');
}

function kili_is_app_authenticated(): bool
{
    if (!kili_app_password_configured()) {
        return true;
    }
    kili_ensure_session();

    return !empty($_SESSION['kili_app_authenticated']);
}

function kili_verify_app_password(string $password): bool
{
    $hash = kili_load_env()['KILI_APP_PASSWORD_HASH'] ?? '';

    return $hash !== '' && password_verify($password, $hash);
}

function kili_set_app_authenticated(bool $value): void
{
    kili_ensure_session();
    $_SESSION['kili_app_authenticated'] = $value;
}

/** For customer-facing JSON API endpoints: exits with 401 if an app password is configured and not yet entered this session. */
function kili_require_app_auth_json(): void
{
    if (!kili_is_app_authenticated()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => ['code' => 'AUTH_REQUIRED', 'message' => 'This app is password-protected. Please unlock it first.'],
        ]);
        exit;
    }
}

function kili_admin_password_configured(): bool
{
    return !empty(kili_load_env()['KILI_ADMIN_PASSWORD_HASH'] ?? '');
}

function kili_is_admin_authenticated(): bool
{
    kili_ensure_session();

    return !empty($_SESSION['kili_admin_authenticated']);
}

function kili_verify_admin_password(string $password): bool
{
    $hash = kili_load_env()['KILI_ADMIN_PASSWORD_HASH'] ?? '';

    return $hash !== '' && password_verify($password, $hash);
}

function kili_set_admin_authenticated(bool $value): void
{
    kili_ensure_session();
    $_SESSION['kili_admin_authenticated'] = $value;
}

/** For admin-only JSON API endpoints: exits with 401 unless an authenticated admin session exists. Always required — never optional. */
function kili_require_admin_auth_json(): void
{
    if (!kili_is_admin_authenticated()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => ['code' => 'ADMIN_AUTH_REQUIRED', 'message' => 'Admin login required.'],
        ]);
        exit;
    }
}

function kili_data_source_engine(): DataSourceEngine
{
    static $engine = null;
    if ($engine === null) {
        $engine = new DataSourceEngine(kili_read_json(__DIR__ . '/config/data_sources.json'));
    }

    return $engine;
}

/** Storage for whichever dataset is currently active — see DataSourceEngine. Falls back to data/data.json if none is configured. */
function kili_storage(): JsonAdapter
{
    static $storage = null;
    if ($storage === null) {
        $activeFile = kili_data_source_engine()->active()['file'] ?? 'data/data.json';
        $storage = new JsonAdapter(__DIR__ . '/' . $activeFile);
    }

    return $storage;
}

/** Switches which configured dataset backs search. Takes effect on the next request (this one may already have a cached kili_storage()). */
function kili_set_active_data_source(string $id): void
{
    $path = __DIR__ . '/config/data_sources.json';
    $config = kili_read_json($path);
    $config['active'] = $id;
    file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/**
 * Publishes newly-imported rows as a brand-new, independently switchable
 * data source rather than merging them into whatever's currently active
 * — the "auto-detect schema, let the user confirm the mapping, then save
 * it as its own dataset" flow. Ties SchemaDetector into the Data Source
 * Engine. Returns the new source's config entry.
 */
function kili_publish_data_source(string $name, array $rows, array $mapping, string $provenanceType = 'import'): array
{
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)), '-') ?: 'source-' . count($rows);
    $file = 'data/' . $slug . '.json';

    $detector = new SchemaDetector();
    $mapped = $detector->applyMapping($rows, $mapping);

    $sourceId = 'src-' . $slug;
    kili_ensure_source($sourceId, $provenanceType, $name);

    $storage = new JsonAdapter(__DIR__ . '/' . $file);
    foreach ($mapped as $record) {
        if (empty($record['title'])) {
            continue;
        }
        $record += ['status' => 'active', 'verified' => false, 'tags' => []];
        $record['source_id'] = $sourceId;
        $storage->save($record);
    }

    $newSource = [
        'id' => $slug,
        'name' => $name,
        'type' => 'json',
        'file' => $file,
        'description' => ($provenanceType === 'import' ? 'Published via the schema detector on ' : 'Indexed from a ' . $provenanceType . ' database on ') . gmdate('Y-m-d'),
    ];

    $path = __DIR__ . '/config/data_sources.json';
    $config = kili_read_json($path);
    $config['sources'][] = $newSource;
    file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

    return $newSource;
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

/** Records an emoji reaction to a specific AI reply — append-only, same shape as query logging. */
function kili_record_feedback(string $emoji, string $reply, array $context = []): void
{
    $path = __DIR__ . '/data/feedback.json';
    $log = kili_read_json($path);

    $log[] = [
        'emoji' => $emoji,
        'reply' => $reply,
        'context' => $context,
        'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];

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
