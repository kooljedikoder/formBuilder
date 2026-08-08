<?php

require_once __DIR__ . '/../bootstrap.php';

if (!kili_admin_password_configured() || !kili_is_admin_authenticated()) {
    header('Location: connections.php');
    exit;
}

$backupsDir = __DIR__ . '/../storage/backups';
if (!is_dir($backupsDir)) {
    mkdir($backupsDir, 0755, true);
}

$do = $_REQUEST['do'] ?? '';
$notice = null;

/** Only ever accept a bare filename matching our own naming scheme — the caller-supplied string never becomes part of a path we build. */
function kili_backup_safe_name(string $name): ?string
{
    return preg_match('/^backup-[0-9]{8}-[0-9]{6}\.zip$/', $name) ? $name : null;
}

function kili_backup_add_dir(ZipArchive $zip, string $dir, string $zipRoot): void
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

if (kili_has_feature('crud')) {
    if ($do === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $filename = 'backup-' . gmdate('Ymd-His') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($backupsDir . '/' . $filename, ZipArchive::CREATE) === true) {
            kili_backup_add_dir($zip, __DIR__ . '/../data', 'data');
            kili_backup_add_dir($zip, __DIR__ . '/../config', 'config');
            $zip->close();
            $notice = ['type' => 'success', 'text' => "Backup created: $filename"];
        } else {
            $notice = ['type' => 'error', 'text' => 'Could not create the backup archive.'];
        }
    } elseif ($do === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $safe = kili_backup_safe_name($_POST['file'] ?? '');
        if ($safe && is_file($backupsDir . '/' . $safe)) {
            unlink($backupsDir . '/' . $safe);
            $notice = ['type' => 'success', 'text' => "Deleted $safe."];
        } else {
            $notice = ['type' => 'error', 'text' => 'Unknown backup file.'];
        }
    } elseif ($do === 'download') {
        $safe = kili_backup_safe_name($_GET['file'] ?? '');
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

function kili_format_bytes(int $bytes): string
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
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>KilliSearch Ultra — Backups</title>
<style>
  body { font-family: -apple-system, Arial, sans-serif; background: #f5f6f8; color: #202124; margin: 0; padding: 24px; }
  .wrap { max-width: 780px; margin: 0 auto; }
  h1 { font-size: 20px; }
  .card { background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; padding: 16px; margin-bottom: 16px; }
  .notice { padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
  .notice.success { background: #e6f4ea; color: #137333; }
  .notice.error { background: #fce8e6; color: #c5221f; }
  .upsell { background: #fef8e8; border: 1px solid #e0b23d; border-radius: 10px; padding: 16px; font-size: 14px; }
  button { padding: 8px 14px; border: none; border-radius: 6px; background: #1a73e8; color: #fff; font-size: 14px; cursor: pointer; }
  button.danger { background: #fff; color: #c5221f; border: 1px solid #c5221f; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 8px; }
  table th, table td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #f0f0f0; }
  a.link { color: #1a73e8; text-decoration: none; }
  form.inline { display: inline; }
  .nav a { font-size: 13px; color: #5f6368; margin-right: 14px; text-decoration: none; }
</style>
</head>
<body>
<div class="wrap">
  <div style="display:flex;justify-content:space-between;align-items:baseline">
    <h1>Backups</h1>
    <span class="nav">
      <a href="connections.php">Connections</a>
      <a href="records.php">Records</a>
    </span>
  </div>
  <p>Zips <code>data/</code> and <code>config/</code> — your listings, taxonomy, branding and package settings. Never includes <code>.env</code> (credentials).</p>

  <?php if ($notice): ?>
    <div class="notice <?= $notice['type'] ?>"><?= htmlspecialchars($notice['text']) ?></div>
  <?php endif; ?>

  <?php if (!kili_has_feature('crud')): ?>
    <div class="upsell">Backups are a Standard/Ultra feature. Activate a license key on the <a class="link" href="connections.php">Connections</a> page to unlock them.</div>
  <?php else: ?>
    <div class="card">
      <form method="post" action="?do=create">
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
          <td><?= kili_format_bytes($b['size']) ?></td>
          <td><?= htmlspecialchars(gmdate('Y-m-d H:i', $b['mtime'])) ?> UTC</td>
          <td>
            <a class="link" href="?do=download&amp;file=<?= urlencode($b['name']) ?>">Download</a>
            &nbsp;
            <form class="inline" method="post" action="?do=delete" onsubmit="return confirm('Delete this backup?')">
              <input type="hidden" name="file" value="<?= htmlspecialchars($b['name']) ?>">
              <button type="submit" class="danger">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
