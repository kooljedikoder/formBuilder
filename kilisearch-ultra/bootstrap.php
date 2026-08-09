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
require_once __DIR__ . '/core/EntitlementManager.php';
require_once __DIR__ . '/core/LicenseManager.php';
require_once __DIR__ . '/core/CrudEngine.php';
require_once __DIR__ . '/core/DbAdapter.php';

use Killi\Adapters\JsonAdapter;
use Killi\Core\SearchEngine;
use Killi\Core\LocationEngine;
use Killi\Core\TaxonomyEngine;
use Killi\Core\SourceRegistry;
use Killi\Core\ConversationEngine;
use Killi\Core\FormEngine;
use Killi\Core\MemoryEngine;
use Killi\Core\DataSourceEngine;
use Killi\Core\SchemaDetector;
use Killi\Core\ConnectionManager;
use Killi\Core\EntitlementManager;
use Killi\Core\LicenseManager;
use Killi\Core\CrudEngine;
use Killi\Core\DbAdapter;
use Killi\Adapters\StorageInterface;

/** Reads a JSON config file, returning [] if it doesn't exist or is invalid. */
function killi_read_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode(file_get_contents($path), true);

    return is_array($data) ? $data : [];
}

/** Simple KEY=VALUE .env parser. Credentials live only here, never in config/*.json, never echoed back by an API. */
function killi_load_env(): array
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

function killi_connection_manager(): ConnectionManager
{
    static $manager = null;
    if ($manager === null) {
        $manager = new ConnectionManager(killi_load_env());
    }

    return $manager;
}

/**
 * Writes/updates one connection profile's keys in .env, preserving
 * everything else in the file. The password field is only written when
 * provided (an empty password field means "keep the existing one" on
 * update) and is never read back out by any API response.
 */
function killi_save_env_profile(string $name, array $fields): void
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
        'KILLI_DB_' . $upper . '_DRIVER' => $fields['driver'],
        'KILLI_DB_' . $upper . '_HOST' => $fields['host'],
        'KILLI_DB_' . $upper . '_PORT' => $fields['port'],
        'KILLI_DB_' . $upper . '_DATABASE' => $fields['database'],
        'KILLI_DB_' . $upper . '_USERNAME' => $fields['username'],
        'KILLI_DB_' . $upper . '_SSL' => !empty($fields['ssl']) ? 'true' : 'false',
    ];
    if (!empty($fields['password'])) {
        $updates['KILLI_DB_' . $upper . '_PASSWORD'] = $fields['password'];
    }

    foreach ($updates as $key => $value) {
        $line = $key . '=' . $value;
        if (isset($existingKeys[$key])) {
            $lines[$existingKeys[$key]] = $line;
        } else {
            $lines[] = $line;
        }
    }

    $profilesKey = 'KILLI_DB_PROFILES';
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
function killi_save_env_value(string $key, string $value): void
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

function killi_ensure_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

/** Per-session CSRF token for admin forms — created once, reused for the life of the session. */
function killi_csrf_token(): string
{
    killi_ensure_session();
    if (empty($_SESSION['killi_csrf'])) {
        $_SESSION['killi_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['killi_csrf'];
}

function killi_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(killi_csrf_token()) . '">';
}

function killi_verify_csrf(string $token): bool
{
    killi_ensure_session();

    return !empty($_SESSION['killi_csrf']) && hash_equals($_SESSION['killi_csrf'], $token);
}

/**
 * Login brute-force protection: 5 failed admin-login attempts locks out
 * further attempts for 15 minutes. Tracked per-IP in a JSON file rather
 * than per-session, since a session is exactly what an attacker restarts
 * on each attempt.
 */
function killi_admin_login_state(string $ip): array
{
    $log = killi_read_json(__DIR__ . '/data/login_attempts.json');

    return $log[$ip] ?? ['failed_count' => 0, 'locked_until' => null];
}

function killi_admin_login_locked(string $ip): int
{
    $state = killi_admin_login_state($ip);
    if (empty($state['locked_until'])) {
        return 0;
    }
    $remaining = strtotime($state['locked_until']) - time();

    return $remaining > 0 ? $remaining : 0;
}

function killi_record_admin_login_attempt(string $ip, bool $success): void
{
    $path = __DIR__ . '/data/login_attempts.json';
    $log = killi_read_json($path);
    $state = $log[$ip] ?? ['failed_count' => 0, 'locked_until' => null];

    if ($success) {
        $state = ['failed_count' => 0, 'locked_until' => null];
    } else {
        $state['failed_count'] = ($state['failed_count'] ?? 0) + 1;
        if ($state['failed_count'] >= 5) {
            $state['locked_until'] = gmdate('Y-m-d\TH:i:s\Z', time() + 900);
        }
    }

    $log[$ip] = $state;
    file_put_contents($path, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/** Best-effort client IP for rate-limiting — not spoof-proof behind an untrusted proxy, but this app has no proxy config to trust XFF against. */
function killi_client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
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
function killi_app_password_configured(): bool
{
    return !empty(killi_load_env()['KILLI_APP_PASSWORD_HASH'] ?? '');
}

function killi_is_app_authenticated(): bool
{
    if (!killi_app_password_configured()) {
        return true;
    }
    killi_ensure_session();

    return !empty($_SESSION['killi_app_authenticated']);
}

function killi_verify_app_password(string $password): bool
{
    $hash = killi_load_env()['KILLI_APP_PASSWORD_HASH'] ?? '';

    return $hash !== '' && password_verify($password, $hash);
}

function killi_set_app_authenticated(bool $value): void
{
    killi_ensure_session();
    $_SESSION['killi_app_authenticated'] = $value;
}

/** For customer-facing JSON API endpoints: exits with 401 if an app password is configured and not yet entered this session. */
function killi_require_app_auth_json(): void
{
    if (!killi_is_app_authenticated()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => ['code' => 'AUTH_REQUIRED', 'message' => 'This app is password-protected. Please unlock it first.'],
        ]);
        exit;
    }
}

/**
 * Multiple named admin accounts (data/admins.json: [{username, password_hash,
 * created_at}]) replaced the original single shared KILLI_ADMIN_PASSWORD_HASH
 * — so the audit log's "who did this" is a real answer, and one admin can be
 * revoked without resetting everyone's access.
 */
function killi_admins(): array
{
    return killi_read_json(__DIR__ . '/data/admins.json');
}

function killi_save_admins(array $admins): void
{
    file_put_contents(__DIR__ . '/data/admins.json', json_encode($admins, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function killi_find_admin(string $username): ?array
{
    $username = mb_strtolower(trim($username));
    foreach (killi_admins() as $admin) {
        if (mb_strtolower($admin['username']) === $username) {
            return $admin;
        }
    }

    return null;
}

function killi_admin_password_configured(): bool
{
    return count(killi_admins()) > 0;
}

/** @throws \InvalidArgumentException if the username is taken or invalid */
/**
 * "owner" can manage other admins, licensing, and database connections —
 * the install-level, higher-blast-radius controls. "editor" gets the
 * day-to-day surfaces (records, FAQ, backup create/download) without
 * those. The very first admin (created during setup) is always "owner"
 * regardless of what's passed, since there's no one yet to have granted
 * them a lesser role.
 */
function killi_create_admin(string $username, string $password, string $role = 'editor'): array
{
    $username = trim($username);
    if (!preg_match('/^[A-Za-z0-9_.-]{2,40}$/', $username)) {
        throw new \InvalidArgumentException('Username must be 2-40 characters: letters, numbers, "_.-" only.');
    }
    if (strlen($password) < 8) {
        throw new \InvalidArgumentException('Password must be at least 8 characters.');
    }
    if (killi_find_admin($username) !== null) {
        throw new \InvalidArgumentException("Username \"$username\" is already taken.");
    }
    if (!in_array($role, ['owner', 'editor'], true)) {
        throw new \InvalidArgumentException('Role must be "owner" or "editor".');
    }

    $admins = killi_admins();
    $admin = [
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => empty($admins) ? 'owner' : $role,
        'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    $admins[] = $admin;
    killi_save_admins($admins);

    return $admin;
}

/** @throws \InvalidArgumentException if this would remove the last admin, or the username is unknown */
function killi_delete_admin(string $username): void
{
    $admins = killi_admins();
    if (count($admins) <= 1) {
        throw new \InvalidArgumentException('Cannot delete the only remaining admin account.');
    }
    $target = killi_find_admin($username);
    if ($target === null) {
        throw new \InvalidArgumentException("Unknown admin \"$username\".");
    }
    $remainingOwners = array_filter($admins, fn($a) => ($a['role'] ?? 'owner') === 'owner' && mb_strtolower($a['username']) !== mb_strtolower($username));
    if (($target['role'] ?? 'owner') === 'owner' && count($remainingOwners) === 0) {
        throw new \InvalidArgumentException('Cannot delete the only remaining owner — promote another admin to owner first.');
    }
    $remaining = array_values(array_filter($admins, fn($a) => mb_strtolower($a['username']) !== mb_strtolower($username)));
    killi_save_admins($remaining);
}

function killi_current_admin_role(): ?string
{
    $admin = killi_find_admin((string) killi_current_admin_username());

    return $admin['role'] ?? null;
}

function killi_is_admin_owner(): bool
{
    return killi_current_admin_role() === 'owner';
}

/** For install-level admin actions (managing other admins, licensing, database connections) — editors don't get these. */
function killi_require_admin_owner(): void
{
    if (!killi_is_admin_owner()) {
        throw new \InvalidArgumentException('Only an owner-level admin can do that.');
    }
}

/** @throws \InvalidArgumentException if the username is unknown or the password is too short */
function killi_change_admin_password(string $username, string $newPassword): void
{
    if (strlen($newPassword) < 8) {
        throw new \InvalidArgumentException('Password must be at least 8 characters.');
    }
    $admins = killi_admins();
    $found = false;
    foreach ($admins as &$admin) {
        if (mb_strtolower($admin['username']) === mb_strtolower($username)) {
            $admin['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
            $found = true;
            break;
        }
    }
    unset($admin);
    if (!$found) {
        throw new \InvalidArgumentException("Unknown admin \"$username\".");
    }
    killi_save_admins($admins);
}

/** Verifies credentials and returns the matched admin record, or null. Timing-safe regardless of whether the username exists. */
function killi_verify_admin_login(string $username, string $password): ?array
{
    $admin = killi_find_admin($username);
    $hash = $admin['password_hash'] ?? '$2y$10$invalidsaltinvalidsaltinvalidsalu';

    if (password_verify($password, $hash) && $admin !== null) {
        return $admin;
    }

    return null;
}

function killi_is_admin_authenticated(): bool
{
    killi_ensure_session();

    return !empty($_SESSION['killi_admin_username']) && killi_find_admin($_SESSION['killi_admin_username']) !== null;
}

function killi_current_admin_username(): ?string
{
    killi_ensure_session();

    return $_SESSION['killi_admin_username'] ?? null;
}

/** Pass a username to log in, or null to log out. */
function killi_set_admin_authenticated(?string $username): void
{
    killi_ensure_session();
    if ($username === null) {
        unset($_SESSION['killi_admin_username']);
    } else {
        $_SESSION['killi_admin_username'] = $username;
    }
}

/** For admin-only JSON API endpoints: exits with 401 unless an authenticated admin session exists. Always required — never optional. */
function killi_require_admin_auth_json(): void
{
    if (!killi_is_admin_authenticated()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => ['code' => 'ADMIN_AUTH_REQUIRED', 'message' => 'Admin login required.'],
        ]);
        exit;
    }
}

function killi_entitlement_manager(): EntitlementManager
{
    static $manager = null;
    if ($manager === null) {
        $manager = new EntitlementManager(killi_read_json(__DIR__ . '/config/packages.json'));
    }

    return $manager;
}

/**
 * Resolves the current user's license package. This is the "plugged into
 * a main app's auth system" hook: a host application authenticates its
 * own users and, before handing off to Killi, sets
 * $_SESSION['killi_host_user'] = ['user_id' => ..., 'package' => ...].
 * Killi trusts that rather than running its own login for this purpose.
 * With no host identity present (running standalone), everything falls
 * back to config/packages.json's default_package — "enterprise" out of
 * the box, so nothing is gated unless a host app (or a future package
 * management screen) says otherwise.
 */
function killi_current_package(): string
{
    killi_ensure_session();
    if (!empty($_SESSION['killi_host_user']['package'])) {
        return $_SESSION['killi_host_user']['package'];
    }

    return killi_entitlement_manager()->defaultPackage();
}

function killi_has_feature(string $feature): bool
{
    return killi_entitlement_manager()->hasFeature(killi_current_package(), $feature);
}

/** For endpoints where lacking a feature is a hard stop (admin operations) rather than something to gracefully degrade around. */
function killi_require_feature_json(string $feature): void
{
    if (!killi_has_feature($feature)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => ['code' => 'FEATURE_NOT_LICENSED', 'message' => 'Your plan does not include this feature.'],
        ]);
        exit;
    }
}

/** Changes the package used when no host app has injected an identity — the standalone-deployment control. */
function killi_set_default_package(string $packageId): void
{
    $path = __DIR__ . '/config/packages.json';
    $config = killi_read_json($path);
    $config['default_package'] = $packageId;
    file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function killi_license_manager(): LicenseManager
{
    static $manager = null;
    if ($manager === null) {
        $manager = new LicenseManager(killi_read_json(__DIR__ . '/data/licenses.json'));
    }

    return $manager;
}

/**
 * Enters a "your license key" flow, per direction: validates against
 * data/licenses.json and, on success, unlocks the matching package for
 * the whole installation via the same killi_set_default_package() used by
 * the admin dropdown — this is just a friendlier, product-appropriate
 * front door onto the same mechanism, not a separate system.
 */
function killi_activate_license(string $key): array
{
    $result = killi_license_manager()->activate($key);

    if ($result['success']) {
        killi_set_default_package($result['package']);
        killi_save_env_value('KILLI_ACTIVE_LICENSE_KEY', $key);
    }

    return $result;
}

function killi_active_license_key(): ?string
{
    $key = killi_load_env()['KILLI_ACTIVE_LICENSE_KEY'] ?? '';

    return $key !== '' ? $key : null;
}

/** Whether the guided standalone setup wizard (admin/setup.php) has been completed or explicitly skipped through. */
function killi_setup_complete(): bool
{
    return (killi_load_env()['KILLI_SETUP_COMPLETE'] ?? '') === 'true';
}

function killi_mark_setup_complete(): void
{
    killi_save_env_value('KILLI_SETUP_COMPLETE', 'true');
}

function killi_data_source_engine(): DataSourceEngine
{
    static $engine = null;
    if ($engine === null) {
        $engine = new DataSourceEngine(killi_read_json(__DIR__ . '/config/data_sources.json'));
    }

    return $engine;
}

/** Storage for whichever dataset is currently active — see DataSourceEngine. Falls back to data/data.json if none is configured. */
function killi_storage(): StorageInterface
{
    static $storage = null;
    if ($storage === null) {
        $active = killi_data_source_engine()->active();
        $storage = $active !== null
            ? killi_build_storage($active)
            : new JsonAdapter(__DIR__ . '/data/data.json');
    }

    return $storage;
}

/** Storage for a specific data source, not necessarily the active one — CRUD/export/backup need to reach any configured source, not just the one Search is currently using. */
function killi_storage_for_source(string $sourceId): StorageInterface
{
    $source = killi_data_source_engine()->find($sourceId);
    if ($source === null) {
        throw new \InvalidArgumentException("Unknown data source \"$sourceId\".");
    }

    return killi_build_storage($source);
}

/**
 * A data source is either a local JSON file (type "json", the default —
 * omitted "type" means "json" for sources predating this key) or a live
 * database table (type "live_db"): queried fresh on every all() call via
 * DbAdapter instead of a cached snapshot, so search reflects rows
 * added/edited/removed in the source table without re-publishing.
 */
function killi_build_storage(array $source): StorageInterface
{
    if (($source['type'] ?? 'json') === 'live_db') {
        return new DbAdapter(
            killi_connection_manager(),
            $source['connection'],
            $source['table'],
            $source['mapping'] ?? [],
            $source['source_id'] ?? null,
            !empty($source['writable'])
        );
    }

    return new JsonAdapter(__DIR__ . '/' . $source['file']);
}

/** The fourth pillar: a validated create/update/delete layer over whichever source is named (defaults to active), instead of hand-editing JSON files. */
function killi_crud_engine(?string $sourceId = null): CrudEngine
{
    $sourceId = $sourceId ?? killi_data_source_engine()->activeId();
    if ($sourceId === null) {
        throw new \InvalidArgumentException('No data source is configured.');
    }

    return new CrudEngine(killi_storage_for_source($sourceId), $sourceId);
}

/** Append-only audit trail for CRUD writes — who (the single shared admin account, for now) did what to which record, when. */
function killi_record_audit(string $action, string $sourceId, string $recordId, string $summary = ''): void
{
    $path = __DIR__ . '/data/audit_log.json';
    $log = killi_read_json($path);

    $log[] = [
        'action' => $action,
        'source_id' => $sourceId,
        'record_id' => $recordId,
        'summary' => $summary,
        'admin' => killi_current_admin_username() ?? 'unknown',
        'at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];

    file_put_contents($path, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/** Switches which configured dataset backs search. Takes effect on the next request (this one may already have a cached killi_storage()). */
function killi_set_active_data_source(string $id): void
{
    $path = __DIR__ . '/config/data_sources.json';
    $config = killi_read_json($path);
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
function killi_publish_data_source(string $name, array $rows, array $mapping, string $provenanceType = 'import'): array
{
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)), '-') ?: 'source-' . count($rows);
    $file = 'data/' . $slug . '.json';

    $detector = new SchemaDetector();
    $mapped = $detector->applyMapping($rows, $mapping);

    $sourceId = 'src-' . $slug;
    killi_ensure_source($sourceId, $provenanceType, $name);

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
    $config = killi_read_json($path);
    $config['sources'][] = $newSource;
    file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

    return $newSource;
}

/**
 * Registers a database table as a source Search queries live, on every
 * request, rather than a point-in-time JSON snapshot. No rows are copied
 * anywhere — this just remembers which connection/table/mapping to ask
 * DbAdapter to query. Read-only by design; see DbAdapter's docblock.
 */
function killi_publish_live_source(string $name, string $connectionName, string $table, array $mapping, bool $writable = false): array
{
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)), '-') ?: 'live-' . $table;
    $sourceId = 'src-' . $slug;
    killi_ensure_source($sourceId, 'database', $name);

    $newSource = [
        'id' => $slug,
        'name' => $name,
        'type' => 'live_db',
        'connection' => $connectionName,
        'table' => $table,
        'mapping' => $mapping,
        'writable' => $writable,
        'source_id' => $sourceId,
        'description' => 'Live query against "' . $table . '" via the "' . $connectionName . '" connection — reflects the table in real time, registered ' . gmdate('Y-m-d') . '.'
            . ($writable ? ' Writable: edits/deletes through Killi write back to this table.' : ' Read-only.'),
    ];

    $path = __DIR__ . '/config/data_sources.json';
    $config = killi_read_json($path);
    $config['sources'][] = $newSource;
    file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

    return $newSource;
}

function killi_search_engine(): SearchEngine
{
    static $engine = null;
    if ($engine === null) {
        $searchConfig = killi_read_json(__DIR__ . '/config/search.json');
        $engine = new SearchEngine(killi_storage()->all(), $searchConfig);
    }

    return $engine;
}

function killi_location_engine(): LocationEngine
{
    static $engine = null;
    if ($engine === null) {
        $engine = new LocationEngine(killi_read_json(__DIR__ . '/data/locations.json'));
    }

    return $engine;
}

function killi_taxonomy_engine(): TaxonomyEngine
{
    static $engine = null;
    if ($engine === null) {
        $engine = new TaxonomyEngine(killi_read_json(__DIR__ . '/data/taxonomy.json'));
    }

    return $engine;
}

function killi_source_registry(): SourceRegistry
{
    static $registry = null;
    if ($registry === null) {
        $registry = new SourceRegistry(killi_read_json(__DIR__ . '/data/sources.json'));
    }

    return $registry;
}

/**
 * Reads a free-text query through LocationEngine/TaxonomyEngine and
 * returns soft ranking hints ("mechanic near vi" -> sector=Automotive,
 * category=Vehicle Repair, subcategory=Mechanic, location=Victoria
 * Island). Shared by api/search.php and api/chat.php.
 */
function killi_extract_context(string $query): array
{
    $locationMatch = killi_location_engine()->extractLocation($query);
    $taxonomyMatch = killi_taxonomy_engine()->extractTaxonomy($query);

    return [
        'location' => $locationMatch['name'] ?? null,
        'sector' => $taxonomyMatch['sector'] ?? null,
        'category' => $taxonomyMatch['category'] ?? null,
        'subcategory' => $taxonomyMatch['subcategory'] ?? null,
    ];
}

function killi_resolve_sources(array $records): array
{
    $registry = killi_source_registry();

    return array_map(function ($record) use ($registry) {
        $record['source'] = $registry->resolve($record['source_id'] ?? null);
        return $record;
    }, $records);
}

/** Registers a source in sources.json if it doesn't already exist (e.g. before an import commits records against it). */
function killi_ensure_source(string $id, string $type, string $name): void
{
    $path = __DIR__ . '/data/sources.json';
    $sources = killi_read_json($path);

    foreach ($sources as $source) {
        if ($source['id'] === $id) {
            return;
        }
    }

    $sources[] = ['id' => $id, 'type' => $type, 'name' => $name, 'url' => null, 'last_synced' => gmdate('Y-m-d\TH:i:s\Z')];
    file_put_contents($path, json_encode($sources, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function killi_conversation_engine(): ConversationEngine
{
    static $engine = null;
    if ($engine === null) {
        $engine = new ConversationEngine(killi_read_json(__DIR__ . '/config/conversation.json'));
    }

    return $engine;
}

function killi_submissions_storage(): JsonAdapter
{
    static $storage = null;
    if ($storage === null) {
        $storage = new JsonAdapter(__DIR__ . '/data/submissions.json');
    }

    return $storage;
}

function killi_form_engine(): FormEngine
{
    static $engine = null;
    if ($engine === null) {
        $engine = new FormEngine(killi_read_json(__DIR__ . '/config/forms.json'));
    }

    return $engine;
}

function killi_memory_engine(): MemoryEngine
{
    static $engine = null;
    if ($engine === null) {
        $engine = new MemoryEngine(killi_read_json(__DIR__ . '/data/faq.json'));
    }

    return $engine;
}

/** Bumps a recalled memory entry's hit_count — lets an admin see which stored answers get reused most. */
function killi_memory_record_hit(string $faqId): void
{
    $path = __DIR__ . '/data/faq.json';
    $entries = killi_read_json($path);

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
function killi_memory_remember_query(string $query): void
{
    $path = __DIR__ . '/data/query_log.json';
    $log = killi_read_json($path);
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
function killi_record_feedback(string $emoji, string $reply, array $context = []): void
{
    $path = __DIR__ . '/data/feedback.json';
    $log = killi_read_json($path);

    $log[] = [
        'emoji' => $emoji,
        'reply' => $reply,
        'context' => $context,
        'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];

    file_put_contents($path, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function killi_branding(): array
{
    return killi_read_json(__DIR__ . '/config/branding.json');
}

function killi_config(): array
{
    return killi_read_json(__DIR__ . '/config/config.json');
}
