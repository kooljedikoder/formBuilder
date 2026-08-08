<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../core/SchemaDetector.php';

use Kili\Core\SchemaDetector;

$do = $_REQUEST['do'] ?? '';
$notice = null;

// Admin is ALWAYS gated — no admin accounts configured means "not set up
// yet," never "wide open." First visit shows a one-time setup form instead
// of the connections UI.
if (!kili_admin_password_configured()) {
    if ($do === 'admin_setup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!kili_verify_csrf($_POST['csrf'] ?? '')) {
            $notice = ['type' => 'error', 'text' => 'Form expired — please try again.'];
        } else {
            $username = trim($_POST['username'] ?? 'admin');
            $password = $_POST['password'] ?? '';
            $confirm = $_POST['password_confirm'] ?? '';
            if ($password !== $confirm) {
                $notice = ['type' => 'error', 'text' => 'Passwords do not match.'];
            } else {
                try {
                    $admin = kili_create_admin($username, $password);
                    kili_set_admin_authenticated($admin['username']);
                    header('Location: ' . (kili_setup_complete() ? 'connections.php' : 'setup.php'));
                    exit;
                } catch (\InvalidArgumentException $e) {
                    $notice = ['type' => 'error', 'text' => $e->getMessage()];
                }
            }
        }
    }
    ?>
    <!doctype html><html><head><meta charset="utf-8"><title>Set up admin access</title>
    <style>body{font-family:-apple-system,Arial,sans-serif;background:#f5f6f8;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}
    .card{background:#fff;border-radius:10px;padding:24px;max-width:340px;width:90%}
    input{width:100%;padding:10px;border:1px solid #d0d0d0;border-radius:6px;margin-top:8px;box-sizing:border-box}
    button{width:100%;margin-top:14px;padding:10px;border:none;border-radius:6px;background:#1a73e8;color:#fff;cursor:pointer}
    .notice{padding:8px 12px;border-radius:6px;margin-top:12px;font-size:13px;background:#fce8e6;color:#c5221f}</style>
    </head><body><form class="card" method="post" action="?do=admin_setup">
      <?= kili_csrf_field() ?>
      <h2 style="margin-top:0">Set up admin access</h2>
      <p style="font-size:13px;color:#5f6368">No admin account exists yet. Create the first one now — this page cannot be used until you do.</p>
      <input name="username" placeholder="Username" value="admin" required autofocus>
      <input type="password" name="password" placeholder="Password (8+ characters)" required>
      <input type="password" name="password_confirm" placeholder="Confirm password" required>
      <button type="submit">Create admin &amp; continue</button>
      <?php if ($notice): ?><div class="notice"><?= htmlspecialchars($notice['text']) ?></div><?php endif; ?>
    </form></body></html>
    <?php
    exit;
}

if ($do === 'admin_login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = kili_client_ip();
    $lockedFor = kili_admin_login_locked($ip);
    if ($lockedFor > 0) {
        $notice = ['type' => 'error', 'text' => 'Too many failed attempts. Try again in ' . ceil($lockedFor / 60) . ' minute(s).'];
    } elseif (!kili_verify_csrf($_POST['csrf'] ?? '')) {
        $notice = ['type' => 'error', 'text' => 'Form expired — please try again.'];
    } else {
        $admin = kili_verify_admin_login($_POST['username'] ?? '', $_POST['password'] ?? '');
        kili_record_admin_login_attempt($ip, $admin !== null);
        if ($admin !== null) {
            kili_set_admin_authenticated($admin['username']);
            header('Location: ' . (kili_setup_complete() ? 'connections.php' : 'setup.php'));
            exit;
        }
        $notice = ['type' => 'error', 'text' => 'Incorrect username or password.'];
    }
}

if ($do === 'admin_logout') {
    kili_set_admin_authenticated(null);
    header('Location: connections.php');
    exit;
}

if (!kili_is_admin_authenticated()) {
    $lockedFor = kili_admin_login_locked(kili_client_ip());
    ?>
    <!doctype html><html><head><meta charset="utf-8"><title>Admin login</title>
    <style>body{font-family:-apple-system,Arial,sans-serif;background:#f5f6f8;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}
    .card{background:#fff;border-radius:10px;padding:24px;max-width:320px;width:90%}
    input{width:100%;padding:10px;border:1px solid #d0d0d0;border-radius:6px;margin-top:8px;box-sizing:border-box}
    button{width:100%;margin-top:14px;padding:10px;border:none;border-radius:6px;background:#1a73e8;color:#fff;cursor:pointer}
    button:disabled{opacity:0.5;cursor:not-allowed}
    .notice{padding:8px 12px;border-radius:6px;margin-top:12px;font-size:13px;background:#fce8e6;color:#c5221f}</style>
    </head><body><form class="card" method="post" action="?do=admin_login">
      <?= kili_csrf_field() ?>
      <h2 style="margin-top:0">Admin login</h2>
      <input name="username" placeholder="Username" required autofocus <?= $lockedFor > 0 ? 'disabled' : '' ?>>
      <input type="password" name="password" placeholder="Password" required <?= $lockedFor > 0 ? 'disabled' : '' ?>>
      <button type="submit" <?= $lockedFor > 0 ? 'disabled' : '' ?>>Log in</button>
      <?php if ($notice): ?><div class="notice"><?= htmlspecialchars($notice['text']) ?></div><?php endif; ?>
    </form></body></html>
    <?php
    exit;
}

$manager = kili_connection_manager();
$preview = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !kili_verify_csrf($_POST['csrf'] ?? '')) {
    $notice = ['type' => 'error', 'text' => 'Form expired — please reload and try again.'];
    $do = '';
}

if ($do === 'set_app_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['app_password'] ?? '';
    if ($password === '') {
        kili_save_env_value('KILI_APP_PASSWORD_HASH', '');
        $notice = ['type' => 'success', 'text' => 'App password removed — the customer-facing app is now open (no password required).'];
    } else {
        kili_save_env_value('KILI_APP_PASSWORD_HASH', password_hash($password, PASSWORD_DEFAULT));
        $notice = ['type' => 'success', 'text' => 'App password set. Visitors will be asked for it before they can use the app.'];
    }
} elseif ($do === 'change_admin_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['password_confirm'] ?? '';
    if ($password !== $confirm) {
        $notice = ['type' => 'error', 'text' => 'Passwords do not match.'];
    } else {
        try {
            kili_change_admin_password(kili_current_admin_username(), $password);
            $notice = ['type' => 'success', 'text' => 'Your password has been changed.'];
        } catch (\InvalidArgumentException $e) {
            $notice = ['type' => 'error', 'text' => $e->getMessage()];
        }
    }
} elseif ($do === 'add_admin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $admin = kili_create_admin($_POST['username'] ?? '', $_POST['password'] ?? '');
        $notice = ['type' => 'success', 'text' => 'Added admin "' . $admin['username'] . '".'];
    } catch (\InvalidArgumentException $e) {
        $notice = ['type' => 'error', 'text' => $e->getMessage()];
    }
} elseif ($do === 'delete_admin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $target = $_POST['username'] ?? '';
    if (mb_strtolower($target) === mb_strtolower((string) kili_current_admin_username())) {
        $notice = ['type' => 'error', 'text' => 'You cannot delete your own account while logged in as it.'];
    } else {
        try {
            kili_delete_admin($target);
            $notice = ['type' => 'success', 'text' => 'Removed admin "' . $target . '".'];
        } catch (\InvalidArgumentException $e) {
            $notice = ['type' => 'error', 'text' => $e->getMessage()];
        }
    }
} elseif ($do === 'set_default_package' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $packageId = $_POST['default_package'] ?? '';
    if (!in_array($packageId, kili_entitlement_manager()->packageIds(), true)) {
        $notice = ['type' => 'error', 'text' => 'Unknown package.'];
    } else {
        kili_set_default_package($packageId);
        $notice = ['type' => 'success', 'text' => "Default package set to \"$packageId\". Applies to anyone Killi doesn't recognize a host-app identity for."];
    }
} elseif ($do === 'activate_license' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = kili_activate_license(trim($_POST['license_key'] ?? ''));
    $notice = ['type' => $result['success'] ? 'success' : 'error', 'text' => $result['success'] ? 'License activated — unlocked the "' . $result['package'] . '" package.' : $result['message']];
} elseif (in_array($do, ['save', 'test', 'preview', 'publish'], true) && !kili_has_feature('db_connections')) {
    $notice = ['type' => 'error', 'text' => 'Database connections are an Ultra feature. Activate an Ultra license key above to unlock them.'];
} elseif ($do === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    if ($name === '' || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        $notice = ['type' => 'error', 'text' => 'Profile name must use only letters, numbers and underscores.'];
    } else {
        kili_save_env_profile($name, $_POST);
        $notice = ['type' => 'success', 'text' => "Saved connection profile \"$name\"."];
    }
} elseif ($do === 'test' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $result = $manager->testConnection($name);
    $notice = ['type' => $result['success'] ? 'success' : 'error', 'text' => $result['message']];
} elseif ($do === 'preview' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $connName = $_POST['connection'] ?? '';
    $table = $_POST['table'] ?? '';
    try {
        $rows = $manager->fetchRows($connName, $table, 200);
        $detector = new SchemaDetector();
        $schema = $detector->detect($rows);
        $preview = [
            'connection' => $connName,
            'table' => $table,
            'schema' => $schema,
            'rows' => $rows,
            'sample' => $detector->applyMapping(array_slice($rows, 0, 5), $schema['mapping']),
        ];
    } catch (\Throwable $e) {
        $notice = ['type' => 'error', 'text' => $e->getMessage()];
    }
} elseif ($do === 'publish' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $connName = $_POST['connection'] ?? '';
    $table = $_POST['table'] ?? '';
    $sourceName = trim($_POST['source_name'] ?? '');
    try {
        $rows = $manager->fetchRows($connName, $table, 500);
        $detector = new SchemaDetector();
        $mapping = json_decode($_POST['mapping'] ?? '{}', true) ?: $detector->detect($rows)['mapping'];
        $profile = $manager->profile($connName);
        if (!empty($_POST['live'])) {
            $newSource = kili_publish_live_source($sourceName ?: $table, $connName, $table, $mapping);
            $modeText = ' as a live query — search will reflect this table in real time, no re-publishing needed';
        } else {
            $newSource = kili_publish_data_source($sourceName ?: $table, $rows, $mapping, $profile['driver'] ?? 'database');
            $modeText = ' as a cached snapshot';
        }
        if (!empty($_POST['activate'])) {
            kili_set_active_data_source($newSource['id']);
        }
        $notice = ['type' => 'success', 'text' => 'Published "' . $newSource['name'] . '"' . $modeText . (!empty($_POST['activate']) ? ', and activated it.' : '.')];
    } catch (\Throwable $e) {
        $notice = ['type' => 'error', 'text' => $e->getMessage()];
    }
}

$profiles = array_map(fn($name) => $manager->profile($name), $manager->profileNames());
$tablesByProfile = [];
foreach ($profiles as $profile) {
    try {
        $tablesByProfile[$profile['name']] = $manager->listTables($profile['name']);
    } catch (\Throwable $e) {
        $tablesByProfile[$profile['name']] = [];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>KilliSearch Ultra — Connections</title>
<style>
  body { font-family: -apple-system, Arial, sans-serif; background: #f5f6f8; color: #202124; margin: 0; padding: 24px; }
  .wrap { max-width: 780px; margin: 0 auto; }
  h1 { font-size: 20px; }
  h2 { font-size: 15px; margin-top: 32px; }
  .card { background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; padding: 16px; margin-bottom: 16px; }
  .notice { padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
  .notice.success { background: #e6f4ea; color: #137333; }
  .notice.error { background: #fce8e6; color: #c5221f; }
  label { display: block; font-size: 12px; color: #5f6368; margin-top: 10px; }
  input, select { width: 100%; padding: 8px; border: 1px solid #d0d0d0; border-radius: 6px; font-size: 14px; box-sizing: border-box; }
  button { margin-top: 12px; padding: 8px 14px; border: none; border-radius: 6px; background: #1a73e8; color: #fff; font-size: 14px; cursor: pointer; }
  button.secondary { background: #fff; color: #1a73e8; border: 1px solid #1a73e8; }
  .profile-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #f0f0f0; }
  .profile-row:last-child { border-bottom: none; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 8px; }
  table th, table td { text-align: left; padding: 4px 8px; border-bottom: 1px solid #f0f0f0; }
  code { background: #f0f0f0; padding: 2px 5px; border-radius: 4px; }
  form.inline { display: inline; }
</style>
</head>
<body>
<div class="wrap">
  <div style="display:flex;justify-content:space-between;align-items:baseline">
    <h1>Data Source Connections</h1>
    <span style="font-size:13px">
      <a href="records.php" style="color:#5f6368;margin-right:14px">Records</a>
      <a href="backup.php" style="color:#5f6368;margin-right:14px">Backups</a>
      <a href="faq.php" style="color:#5f6368;margin-right:14px">FAQ</a>
      <a href="setup.php" style="color:#5f6368;margin-right:14px">Re-run setup wizard</a>
      <a href="?do=admin_logout" style="color:#5f6368">Log out</a>
    </span>
  </div>
  <p>Credentials live in <code>.env</code> only — never in <code>config/data_sources.json</code>, never sent back to this page after saving.</p>

  <?php if ($notice): ?>
    <div class="notice <?= $notice['type'] ?>"><?= htmlspecialchars($notice['text']) ?></div>
  <?php endif; ?>

  <?php $packagesConfig = kili_read_json(__DIR__ . '/../config/packages.json'); ?>
  <div class="card">
    <h2 style="margin-top:0">Licensing / packages</h2>
    <p style="font-size:13px;color:#5f6368">Used when Killi can't see a host application's identity (no <code>$_SESSION['kili_host_user']</code>) — e.g. running fully standalone, or before a real host-app integration exists. See <code>examples/host-app-demo.php</code> for how a host app assigns a package per-user instead.</p>
    <table>
      <tr><th>Package</th><th>Features</th></tr>
      <?php foreach ($packagesConfig['packages'] ?? [] as $pkg): ?>
        <tr>
          <td><?= htmlspecialchars($pkg['name']) ?><?= $pkg['id'] === ($packagesConfig['default_package'] ?? '') ? ' <strong>(default)</strong>' : '' ?></td>
          <td><?= htmlspecialchars(implode(', ', $pkg['features'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <form method="post" action="?do=set_default_package">
      <?= kili_csrf_field() ?>
      <label>Default package (manual override)</label>
      <select name="default_package">
        <?php foreach ($packagesConfig['packages'] ?? [] as $pkg): ?>
          <option value="<?= htmlspecialchars($pkg['id']) ?>" <?= $pkg['id'] === ($packagesConfig['default_package'] ?? '') ? 'selected' : '' ?>><?= htmlspecialchars($pkg['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit">Save default</button>
    </form>

    <h2>Activate a license key</h2>
    <p style="font-size:13px;color:#5f6368">Enter the license key for this installation to unlock the package it's licensed for — simplest way to move off the free/Basic tier, no dropdown-picking required.</p>
    <?php $activeKey = kili_active_license_key(); ?>
    <p style="font-size:13px">Currently activated: <?= $activeKey ? '<code>' . htmlspecialchars($activeKey) . '</code>' : '<em>none</em>' ?></p>
    <form method="post" action="?do=activate_license">
      <?= kili_csrf_field() ?>
      <input name="license_key" placeholder="e.g. KILI-ULTRA-XXXX-XXXX" value="<?= htmlspecialchars($activeKey ?? '') ?>" required>
      <button type="submit">Activate</button>
    </form>
  </div>

  <div class="card">
    <h2 style="margin-top:0">App access</h2>
    <p style="font-size:13px;color:#5f6368">Optional — off by default so the customer-facing app stays zero-friction. Turn this on to require a shared password before anyone can use it.</p>
    <p style="font-size:13px">Status: <?= kili_app_password_configured() ? '<strong style="color:#137333">password required</strong>' : '<strong>open, no password</strong>' ?></p>
    <form method="post" action="?do=set_app_password">
      <?= kili_csrf_field() ?>
      <label>New app password</label>
      <input type="password" name="app_password" placeholder="Leave blank to remove the password / keep it open">
      <button type="submit">Save</button>
    </form>
  </div>

  <div class="card">
    <h2 style="margin-top:0">Admin accounts</h2>
    <p style="font-size:13px;color:#5f6368">Logged in as <strong><?= htmlspecialchars((string) kili_current_admin_username()) ?></strong>. Every create/edit/delete on the <a href="records.php">Records</a> page is attributed to whichever account made it.</p>
    <table>
      <tr><th>Username</th><th>Created</th><th></th></tr>
      <?php foreach (kili_admins() as $a): ?>
        <tr>
          <td><?= htmlspecialchars($a['username']) ?><?= mb_strtolower($a['username']) === mb_strtolower((string) kili_current_admin_username()) ? ' (you)' : '' ?></td>
          <td><?= htmlspecialchars(substr($a['created_at'] ?? '', 0, 10)) ?></td>
          <td>
            <?php if (mb_strtolower($a['username']) !== mb_strtolower((string) kili_current_admin_username()) && count(kili_admins()) > 1): ?>
            <form class="inline" method="post" action="?do=delete_admin" onsubmit="return confirm('Remove this admin account?')">
              <?= kili_csrf_field() ?>
              <input type="hidden" name="username" value="<?= htmlspecialchars($a['username']) ?>">
              <button type="submit" style="background:#fff;color:#c5221f;border:1px solid #c5221f">Remove</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <h2>Add an admin account</h2>
    <form method="post" action="?do=add_admin">
      <?= kili_csrf_field() ?>
      <label>Username</label>
      <input name="username" required>
      <label>Password</label>
      <input type="password" name="password" placeholder="8+ characters" required>
      <button type="submit">Add admin</button>
    </form>
    <h2>Change your password</h2>
    <form method="post" action="?do=change_admin_password">
      <?= kili_csrf_field() ?>
      <label>New password</label>
      <input type="password" name="password" placeholder="8+ characters" required>
      <label>Confirm</label>
      <input type="password" name="password_confirm" required>
      <button type="submit">Change password</button>
    </form>
  </div>

  <?php if (!kili_has_feature('db_connections')): ?>
  <div class="card">
    <h2 style="margin-top:0">Database connections</h2>
    <p style="font-size:13px;color:#5f6368">Connecting a MySQL/PostgreSQL database (cached snapshot or live query) is an Ultra feature. Activate an Ultra license key above to unlock it.</p>
  </div>
  <?php else: ?>
  <div class="card">
    <h2 style="margin-top:0">Configured profiles</h2>
    <?php if (empty($profiles)): ?>
      <p>No connections configured yet.</p>
    <?php endif; ?>
    <?php foreach ($profiles as $profile): ?>
      <div class="profile-row">
        <div>
          <strong><?= htmlspecialchars($profile['name']) ?></strong>
          — <?= htmlspecialchars($profile['driver']) ?> · <?= htmlspecialchars($profile['host']) ?>:<?= htmlspecialchars($profile['port']) ?>/<?= htmlspecialchars($profile['database']) ?>
          <?= $profile['has_password'] ? '· password saved' : '· <span style="color:#c5221f">no password</span>' ?>
        </div>
        <form class="inline" method="post" action="?do=test">
          <?= kili_csrf_field() ?>
          <input type="hidden" name="name" value="<?= htmlspecialchars($profile['name']) ?>">
          <button type="submit" class="secondary">Test</button>
        </form>
      </div>

      <?php if (!empty($tablesByProfile[$profile['name']])): ?>
        <table>
          <tr><th>Table</th><th></th></tr>
          <?php foreach ($tablesByProfile[$profile['name']] as $table): ?>
            <tr>
              <td><?= htmlspecialchars($table) ?></td>
              <td>
                <form class="inline" method="post" action="?do=preview">
                  <?= kili_csrf_field() ?>
                  <input type="hidden" name="connection" value="<?= htmlspecialchars($profile['name']) ?>">
                  <input type="hidden" name="table" value="<?= htmlspecialchars($table) ?>">
                  <button type="submit" class="secondary">Detect &amp; preview</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <h2 style="margin-top:0">Add a connection</h2>
    <form method="post" action="?do=save">
      <?= kili_csrf_field() ?>
      <label>Profile name</label>
      <input name="name" placeholder="e.g. default" required>
      <label>Driver</label>
      <select name="driver">
        <option value="postgres">PostgreSQL</option>
        <option value="mysql">MySQL</option>
      </select>
      <label>Host</label>
      <input name="host" placeholder="127.0.0.1" required>
      <label>Port</label>
      <input name="port" placeholder="5432">
      <label>Database</label>
      <input name="database" required>
      <label>Username</label>
      <input name="username" required>
      <label>Password</label>
      <input type="password" name="password" placeholder="Leave blank to keep the existing password">
      <label><input type="checkbox" name="ssl" value="1" style="width:auto;display:inline"> Use SSL</label>
      <button type="submit">Save connection</button>
    </form>
  </div>

  <?php if ($preview): ?>
  <div class="card">
    <h2 style="margin-top:0">Preview — <?= htmlspecialchars($preview['table']) ?></h2>
    <p><?= count($preview['rows']) ?> rows fetched. Detected mapping:</p>
    <table>
      <tr><th>Column</th><th>Maps to</th></tr>
      <?php foreach ($preview['schema']['mapping'] as $column => $field): ?>
        <tr><td><?= htmlspecialchars($column) ?></td><td><?= htmlspecialchars($field ?? '(unmapped)') ?></td></tr>
      <?php endforeach; ?>
    </table>
    <p>Sample mapped record:</p>
    <pre style="background:#f5f5f5;padding:10px;border-radius:8px;overflow-x:auto"><?= htmlspecialchars(json_encode($preview['sample'][0] ?? [], JSON_PRETTY_PRINT)) ?></pre>

    <form method="post" action="?do=publish">
      <?= kili_csrf_field() ?>
      <input type="hidden" name="connection" value="<?= htmlspecialchars($preview['connection']) ?>">
      <input type="hidden" name="table" value="<?= htmlspecialchars($preview['table']) ?>">
      <input type="hidden" name="mapping" value='<?= htmlspecialchars(json_encode($preview['schema']['mapping'])) ?>'>
      <label>New data source name</label>
      <input name="source_name" value="<?= htmlspecialchars($preview['table']) ?>">
      <label><input type="checkbox" name="live" value="1" style="width:auto;display:inline"> Live query (reflects the table in real time — no re-publishing when rows change; read-only, no CRUD)</label>
      <label><input type="checkbox" name="activate" value="1" checked style="width:auto;display:inline"> Activate immediately</label>
      <button type="submit">Publish as data source</button>
    </form>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>
