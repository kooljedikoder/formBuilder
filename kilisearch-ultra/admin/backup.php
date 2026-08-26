<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_chrome.php';

if (!killi_admin_password_configured() || !killi_is_admin_authenticated()) {
    header('Location: connections.php');
    exit;
}

$backupsDir = __DIR__ . '/../storage/backups';
if (!is_dir($backupsDir)) {
    mkdir($backupsDir, 0755, true);
}

$do = $_REQUEST['do'] ?? '';
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !killi_verify_csrf($_POST['csrf'] ?? '')) {
    $notice = ['type' => 'error', 'text' => 'Form expired — please reload and try again.'];
    $do = '';
}

/** Only ever accept a bare filename matching our own naming scheme — the caller-supplied string never becomes part of a path we build. */
function killi_backup_safe_name(string $name): ?string
{
    return preg_match('/^backup-[0-9]{8}-[0-9]{6}\.zip$/', $name) ? $name : null;
}

function killi_backup_add_dir(ZipArchive $zip, string $dir, string $zipRoot): void
{
    $base = realpath($dir);
    if ($base === false) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $localPath = $zipRoot . '/' . ltrim(str_replace($base, '', $file->getPathname()), '/\\');
        $zip->addFile($file->getPathname(), $localPath);
    }
}

/**
 * Restoring is the one operation here that writes attacker-influenceable
 * content back into the app's own data/config directories, so entries are
 * on an allowlist rather than trusted: exactly "data/<name>.json" or
 * "config/<name>.json", flat (no subdirectories, no "..", no absolute
 * paths), and the content itself must parse as valid JSON before it's
 * written. Anything else in the archive is silently skipped, not merged.
 */
function killi_backup_restore(string $zipPath): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return ['restored' => 0, 'skipped' => 0, 'error' => 'Could not open that file as a zip archive.'];
    }

    $restored = 0;
    $skipped = 0;
    $root = realpath(__DIR__ . '/..');

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (!preg_match('#^(data|config)/[A-Za-z0-9_.-]+\.json$#', $name)) {
            $skipped++;
            continue;
        }

        $content = $zip->getFromName($name);
        json_decode($content ?: '', true);
        if ($content === false || json_last_error() !== JSON_ERROR_NONE) {
            $skipped++;
            continue;
        }

        $destination = $root . '/' . $name;
        if (strncmp(realpath(dirname($destination)) ?: '', $root, strlen($root)) !== 0) {
            $skipped++;
            continue;
        }

        file_put_contents($destination, $content, LOCK_EX);
        $restored++;
    }

    $zip->close();

    return ['restored' => $restored, 'skipped' => $skipped, 'error' => null];
}

if (killi_has_feature('crud')) {
    if ($do === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $filename = 'backup-' . gmdate('Ymd-His') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($backupsDir . '/' . $filename, ZipArchive::CREATE) === true) {
            killi_backup_add_dir($zip, __DIR__ . '/../data', 'data');
            killi_backup_add_dir($zip, __DIR__ . '/../config', 'config');
            $zip->close();
            $notice = ['type' => 'success', 'text' => "Backup created: $filename"];
        } else {
            $notice = ['type' => 'error', 'text' => 'Could not create the backup archive.'];
        }
    } elseif (in_array($do, ['delete', 'restore_existing', 'restore_upload'], true) && !killi_is_admin_owner()) {
        $notice = ['type' => 'error', 'text' => 'Only an owner-level admin can delete or restore backups.'];
    } elseif ($do === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $safe = killi_backup_safe_name($_POST['file'] ?? '');
        if ($safe && is_file($backupsDir . '/' . $safe)) {
            unlink($backupsDir . '/' . $safe);
            $notice = ['type' => 'success', 'text' => "Deleted $safe."];
        } else {
            $notice = ['type' => 'error', 'text' => 'Unknown backup file.'];
        }
    } elseif ($do === 'restore_existing' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $safe = killi_backup_safe_name($_POST['file'] ?? '');
        if (!$safe || !is_file($backupsDir . '/' . $safe)) {
            $notice = ['type' => 'error', 'text' => 'Unknown backup file.'];
        } else {
            $result = killi_backup_restore($backupsDir . '/' . $safe);
            $notice = $result['error']
                ? ['type' => 'error', 'text' => $result['error']]
                : ['type' => 'success', 'text' => "Restored {$result['restored']} file(s) from $safe." . ($result['skipped'] ? " Skipped {$result['skipped']} unrecognized entr" . ($result['skipped'] === 1 ? 'y' : 'ies') . '.' : '')];
        }
    } elseif ($do === 'restore_upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_FILES['restore_file']['tmp_name']) || $_FILES['restore_file']['error'] !== UPLOAD_ERR_OK) {
            $notice = ['type' => 'error', 'text' => 'No valid zip file was uploaded.'];
        } else {
            $result = killi_backup_restore($_FILES['restore_file']['tmp_name']);
            $notice = $result['error']
                ? ['type' => 'error', 'text' => $result['error']]
                : ['type' => 'success', 'text' => "Restored {$result['restored']} file(s) from the uploaded archive." . ($result['skipped'] ? " Skipped {$result['skipped']} unrecognized entr" . ($result['skipped'] === 1 ? 'y' : 'ies') . '.' : '')];
        }
    } elseif ($do === 'download') {
        $safe = killi_backup_safe_name($_GET['file'] ?? '');
        $path = $safe ? $backupsDir . '/' . $safe : null;
        if ($path && is_file($path)) {
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $safe . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        }
        http_response_code(404);
        exit('Unknown backup file.');
    }
}

$backups = [];
foreach (glob($backupsDir . '/backup-*.zip') as $file) {
    $backups[] = ['name' => basename($file), 'size' => filesize($file), 'mtime' => filemtime($file)];
}
usort($backups, fn($a, $b) => $b['mtime'] <=> $a['mtime']);

function killi_format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $units = ['KB', 'MB', 'GB'];
    $value = $bytes;
    foreach ($units as $unit) {
        $value /= 1024;
        if ($value < 1024) {
            return round($value, 1) . ' ' . $unit;
        }
    }
    return round($value, 1) . ' TB';
}
?>
<?php
killi_admin_head('Backups');
killi_admin_body_open('backup');
?>
  <h1>Backups</h1>
  <p class="lead">Zips <code>data/</code> and <code>config/</code> — your listings, taxonomy, branding and package settings. Never includes <code>.env</code> (credentials).</p>

  <?php if ($notice): ?>
    <div class="notice <?= $notice['type'] ?>"><?= htmlspecialchars($notice['text']) ?></div>
  <?php endif; ?>

  <?php if (!killi_has_feature('crud')): ?>
    <div class="upsell">Backups are a Standard/Ultra feature. Activate a license key on the <a class="link" href="connections.php">Connections</a> page to unlock them.</div>
  <?php else: ?>
    <div class="card">
      <form method="post" action="?do=create">
        <?= killi_csrf_field() ?>
        <button type="submit">Create backup now</button>
      </form>
    </div>

    <div class="card">
      <h2 style="margin-top:0;font-size:15px">Existing backups</h2>
      <?php if (!$backups): ?>
        <p style="font-size:13px;color:#5f6368">No backups yet.</p>
      <?php else: ?>
      <table>
        <tr><th>File</th><th>Size</th><th>Created</th><th></th></tr>
        <?php foreach ($backups as $b): ?>
        <tr>
          <td><?= htmlspecialchars($b['name']) ?></td>
          <td><?= killi_format_bytes($b['size']) ?></td>
          <td><?= htmlspecialchars(gmdate('Y-m-d H:i', $b['mtime'])) ?> UTC</td>
          <td>
            <a class="link" href="?do=download&amp;file=<?= urlencode($b['name']) ?>">Download</a>
            <?php if (killi_is_admin_owner()): ?>
            &nbsp;
            <form class="inline" method="post" action="?do=restore_existing" onsubmit="return confirm('Restore will overwrite current data/ and config/ JSON files with this backup’s contents. Continue?')">
              <?= killi_csrf_field() ?>
              <input type="hidden" name="file" value="<?= htmlspecialchars($b['name']) ?>">
              <button type="submit" class="secondary">Restore</button>
            </form>
            &nbsp;
            <form class="inline" method="post" action="?do=delete" onsubmit="return confirm('Delete this backup?')">
              <?= killi_csrf_field() ?>
              <input type="hidden" name="file" value="<?= htmlspecialchars($b['name']) ?>">
              <button type="submit" class="danger">Delete</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
    </div>

    <?php if (killi_is_admin_owner()): ?>
    <div class="card">
      <h2 style="margin-top:0;font-size:15px">Restore from an uploaded backup</h2>
      <p style="font-size:13px;color:#5f6368">Only recognizes flat <code>data/*.json</code> and <code>config/*.json</code> entries with valid JSON content — anything else in the zip is skipped, not merged. Overwrites the matching live files; doesn't remove files added since the backup was made.</p>
      <form method="post" action="?do=restore_upload" enctype="multipart/form-data" onsubmit="return confirm('Restore will overwrite current data/ and config/ JSON files with this archive’s contents. Continue?')">
        <?= killi_csrf_field() ?>
        <input type="file" name="restore_file" accept=".zip" required>
        <button type="submit" class="danger">Restore from this file</button>
      </form>
    </div>
    <?php endif; ?>
  <?php endif; ?>
<?php killi_admin_body_close(); ?>
