<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../core/SchemaDetector.php';

use Kili\Core\SchemaDetector;

$manager = kili_connection_manager();
$do = $_REQUEST['do'] ?? '';
$notice = null;
$preview = null;

if ($do === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
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
        $newSource = kili_publish_data_source($sourceName ?: $table, $rows, $mapping, $profile['driver'] ?? 'database');
        if (!empty($_POST['activate'])) {
            kili_set_active_data_source($newSource['id']);
        }
        $notice = ['type' => 'success', 'text' => 'Published "' . $newSource['name'] . '" as a new data source' . (!empty($_POST['activate']) ? ' and activated it.' : '.')];
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
<title>KiliSearch Ultra — Connections</title>
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
  <h1>Data Source Connections</h1>
  <p>Credentials live in <code>.env</code> only — never in <code>config/data_sources.json</code>, never sent back to this page after saving.</p>

  <?php if ($notice): ?>
    <div class="notice <?= $notice['type'] ?>"><?= htmlspecialchars($notice['text']) ?></div>
  <?php endif; ?>

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
      <input type="hidden" name="connection" value="<?= htmlspecialchars($preview['connection']) ?>">
      <input type="hidden" name="table" value="<?= htmlspecialchars($preview['table']) ?>">
      <input type="hidden" name="mapping" value='<?= htmlspecialchars(json_encode($preview['schema']['mapping'])) ?>'>
      <label>New data source name</label>
      <input name="source_name" value="<?= htmlspecialchars($preview['table']) ?>">
      <label><input type="checkbox" name="activate" value="1" checked style="width:auto;display:inline"> Activate immediately</label>
      <button type="submit">Publish as data source</button>
    </form>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
