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
use Kili\Core\EntitlementManager;
use Kili\Core\LicenseManager;
use Kili\Core\CrudEngine;
use Kili\Core\DbAdapter;
use Kili\Adapters\StorageInterface;

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

/** Per-session CSRF token for admin forms — created once, reused for the life of the session. */
function kili_csrf_token(): string
{
    kili_ensure_session();
    if (empty($_SESSION['kili_csrf'])) {
        $_SESSION['kili_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['kili_csrf'];
}

function kili_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(kili_csrf_token()) . '">';
}

function kili_verify_csrf(string $token): bool
{
    kili_ensure_session();

    return !empty($_SESSION['kili_csrf']) && hash_equals($_SESSION['kili_csrf'], $token);
}

/**
 * Login brute-force protection: 5 failed admin-login attempts locks out
 * further attempts for 15 minutes. Tracked per-IP in a JSON file rather
 * than per-session, since a session is exactly what an attacker restarts
 * on each attempt.
 */
function kili_admin_login_state(string $ip): array
{
    $log = kili_read_json(__DIR__ . '/data/login_attempts.json');

    return $log[$ip] ?? ['failed_count' => 0, 'locked_until' => null];
}

function kili_admin_login_locked(string $ip): int
{
    $state = kili_admin_login_state($ip);
    if (empty($state['locked_until'])) {
        return 0;
    }
    $remaining = strtotime($state['locked_until']) - time();

    return $remaining > 0 ? $remaining : 0;
}

function kili_record_admin_login_attempt(string $ip, bool $success): void
{
    $path = __DIR__ . '/data/login_attempts.json';
    $log = kili_read_json($path);
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
function kili_client_ip(): string
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

/**
 * Multiple named admin accounts (data/admins.json: [{username, password_hash,
 * created_at}]) replaced the original single shared KILI_ADMIN_PASSWORD_HASH
 * — so the audit log's "who did this" is a real answer, and one admin can be
 * revoked without resetting everyone's access.
 */
function kili_admins(): array
{
    return kili_read_json(__DIR__ . '/data/admins.json');
}

function kili_save_admins(array $admins): void
{
    file_put_contents(__DIR__ . '/data/admins.json', json_encode($admins, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function kili_find_admin(string $username): ?array
{
    $username = mb_strtolower(trim($username));
    foreach (kili_admins() as $admin) {
        if (mb_strtolower($admin['username']) === $username) {
            return $admin;
        }
    }

    return null;
}

function kili_admin_password_configured(): bool
{
    return count(kili_admins()) > 0;
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
function kili_create_admin(string $username, string $password, string $role = 'editor'): array
{
    $username = trim($username);
    if (!preg_match('/^[A-Za-z0-9_.-]{2,40}$/', $username)) {
        throw new \InvalidArgumentException('Username must be 2-40 characters: letters, numbers, "_.-" only.');
    }
    if (strlen($password) < 8) {
        throw new \InvalidArgumentException('Password must be at least 8 characters.');
    }
    if (kili_find_admin($username) !== null) {
        throw new \InvalidArgumentException("Username \"$username\" is already taken.");
    }
    if (!in_array($role, ['owner', 'editor'], true)) {
        throw new \InvalidArgumentException('Role must be "owner" or "editor".');
    }

    $admins = kili_admins();
    $admin = [
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => empty($admins) ? 'owner' : $role,
        'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    $admins[] = $admin;
    kili_save_admins($admins);

    return $admin;
}

/** @throws \InvalidArgumentException if this would remove the last admin, or the username is unknown */
function kili_delete_admin(string $username): void
{
    $admins = kili_admins();
    if (count($admins) <= 1) {
        throw new \InvalidArgumentException('Cannot delete the only remaining admin account.');
    }
    $target = kili_find_admin($username);
    if ($target === null) {
        throw new \InvalidArgumentException("Unknown admin \"$username\".");
    }
    $remainingOwners = array_filter($admins, fn($a) => ($a['role'] ?? 'owner') === 'owner' && mb_strtolower($a['username']) !== mb_strtolower($username));
    if (($target['role'] ?? 'owner') === 'owner' && count($remainingOwners) === 0) {
        throw new \InvalidArgumentException('Cannot delete the only remaining owner — promote another admin to owner first.');
    }
    $remaining = array_values(array_filter($admins, fn($a) => mb_strtolower($a['username']) !== mb_strtolower($username)));
    kili_save_admins($remaining);
}

function kili_current_admin_role(): ?string
{
    $admin = kili_find_admin((string) kili_current_admin_username());

    return $admin['role'] ?? null;
}

function kili_is_admin_owner(): bool
{
    return kili_current_admin_role() === 'owner';
}

/** For install-level admin actions (managing other admins, licensing, database connections) — editors don't get these. */
function kili_require_admin_owner(): void
{
    if (!kili_is_admin_owner()) {
        throw new \InvalidArgumentException('Only an owner-level admin can do that.');
    }
}

/** @throws \InvalidArgumentException if the username is unknown or the password is too short */
function kili_change_admin_password(string $username, string $newPassword): void
{
    if (strlen($newPassword) < 8) {
        throw new \InvalidArgumentException('Password must be at least 8 characters.');
    }
    $admins = kili_admins();
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
    kili_save_admins($admins);
}

/** Verifies credentials and returns the matched admin record, or null. Timing-safe regardless of whether the username exists. */
function kili_verify_admin_login(string $username, string $password): ?array
{
    $admin = kili_find_admin($username);
    $hash = $admin['password_hash'] ?? '$2y$10$invalidsaltinvalidsaltinvalidsalu';

    if (password_verify($password, $hash) && $admin !== null) {
        return $admin;
    }

    return null;
}

function kili_is_admin_authenticated(): bool
{
    kili_ensure_session();

    return !empty($_SESSION['kili_admin_username']) && kili_find_admin($_SESSION['kili_admin_username']) !== null;
}

function kili_current_admin_username(): ?string
{
    kili_ensure_session();

    return $_SESSION['kili_admin_username'] ?? null;
}

/** Pass a username to log in, or null to log out. */
function kili_set_admin_authenticated(?string $username): void
{
    kili_ensure_session();
    if ($username === null) {
        unset($_SESSION['kili_admin_username']);
    } else {
        $_SESSION['kili_admin_username'] = $username;
    }
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

function kili_entitlement_manager(): EntitlementManager
{
    static $manager = null;
    if ($manager === null) {
        $manager = new EntitlementManager(kili_read_json(__DIR__ . '/config/packages.json'));
    }

    return $manager;
}

/**
 * Resolves the current user's license package. This is the "plugged into
 * a main app's auth system" hook: a host application authenticates its
 * own users and, before handing off to Kili, sets
 * $_SESSION['kili_host_user'] = ['user_id' => ..., 'package' => ...].
 * Kili trusts that rather than running its own login for this purpose.
 * With no host identity present (running standalone), everything falls
 * back to config/packages.json's default_package — "enterprise" out of
 * the box, so nothing is gated unless a host app (or a future package
 * management screen) says otherwise.
 */
function kili_current_package(): string
{
    kili_ensure_session();
    if (!empty($_SESSION['kili_host_user']['package'])) {
        return $_SESSION['kili_host_user']['package'];
    }

    return kili_entitlement_manager()->defaultPackage();
}

function kili_has_feature(string $feature): bool
{
    return kili_entitlement_manager()->hasFeature(kili_current_package(), $feature);
}

/** For endpoints where lacking a feature is a hard stop (admin operations) rather than something to gracefully degrade around. */
function kili_require_feature_json(string $feature): void
{
    if (!kili_has_feature($feature)) {
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
function kili_set_default_package(string $packageId): void
{
    $path = __DIR__ . '/config/packages.json';
    $config = kili_read_json($path);
    $config['default_package'] = $packageId;
    file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function kili_license_manager(): LicenseManager
{
    static $manager = null;
    if ($manager === null) {
        $manager = new LicenseManager(kili_read_json(__DIR__ . '/data/licenses.json'));
    }

    return $manager;
}

/**
 * Enters a "your license key" flow, per direction: validates against
 * data/licenses.json and, on success, unlocks the matching package for
 * the whole installation via the same kili_set_default_package() used by
 * the admin dropdown — this is just a friendlier, product-appropriate
 * front door onto the same mechanism, not a separate system.
 */
function kili_activate_license(string $key): array
{
    $result = kili_license_manager()->activate($key);

    if ($result['success']) {
        kili_set_default_package($result['package']);
        kili_save_env_value('KILI_ACTIVE_LICENSE_KEY', $key);
    }

    return $result;
}

function kili_active_license_key(): ?string
{
    $key = kili_load_env()['KILI_ACTIVE_LICENSE_KEY'] ?? '';

    return $key !== '' ? $key : null;
}

/** Whether the guided standalone setup wizard (admin/setup.php) has been completed or explicitly skipped through. */
function kili_setup_complete(): bool
{
    return (kili_load_env()['KILI_SETUP_COMPLETE'] ?? '') === 'true';
}

function kili_mark_setup_complete(): void
{
    kili_save_env_value('KILI_SETUP_COMPLETE', 'true');
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
function kili_storage(): StorageInterface
{
    static $storage = null;
    if ($storage === null) {
        $active = kili_data_source_engine()->active();
        $storage = $active !== null
            ? kili_build_storage($active)
            : new JsonAdapter(__DIR__ . '/data/data.json');
    }

    return $storage;
}

/** Storage for a specific data source, not necessarily the active one — CRUD/export/backup need to reach any configured source, not just the one Search is currently using. */
function kili_storage_for_source(string $sourceId): StorageInterface
{
    $source = kili_data_source_engine()->find($sourceId);
    if ($source === null) {
        throw new \InvalidArgumentException("Unknown data source \"$sourceId\".");
    }

    return kili_build_storage($source);
}

/**
 * A data source is either a local JSON file (type "json", the default —
 * omitted "type" means "json" for sources predating this key) or a live
 * database table (type "live_db"): queried fresh on every all() call via
 * DbAdapter instead of a cached snapshot, so search reflects rows
 * added/edited/removed in the source table without re-publishing.
 */
function kili_build_storage(array $source): StorageInterface
{
    if (($source['type'] ?? 'json') === 'live_db') {
        return new DbAdapter(
            kili_connection_manager(),
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
function kili_crud_engine(?string $sourceId = null): CrudEngine
{
    $sourceId = $sourceId ?? kili_data_source_engine()->activeId();
    if ($sourceId === null) {
        throw new \InvalidArgumentException('No data source is configured.');
    }

    return new CrudEngine(kili_storage_for_source($sourceId), $sourceId);
}

/** Append-only audit trail for CRUD writes — who (the single shared admin account, for now) did what to which record, when. */
function kili_record_audit(string $action, string $sourceId, string $recordId, string $summary = ''): void
{
    $path = __DIR__ . '/data/audit_log.json';
    $log = kili_read_json($path);

    $log[] = [
        'action' => $action,
        'source_id' => $sourceId,
        'record_id' => $recordId,
        'summary' => $summary,
        'admin' => kili_current_admin_username() ?? 'unknown',
        'at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];

    file_put_contents($path, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
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

/**
 * Registers a database table as a source Search queries live, on every
 * request, rather than a point-in-time JSON snapshot. No rows are copied
 * anywhere — this just remembers which connection/table/mapping to ask
 * DbAdapter to query. Read-only by design; see DbAdapter's docblock.
 */
function kili_publish_live_source(string $name, string $connectionName, string $table, array $mapping, bool $writable = false): array
{
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)), '-') ?: 'live-' . $table;
    $sourceId = 'src-' . $slug;
    kili_ensure_source($sourceId, 'database', $name);

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
            . ($writable ? ' Writable: edits/deletes through Kili write back to this table.' : ' Read-only.'),
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
