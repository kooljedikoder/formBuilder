<?php

require_once __DIR__ . '/../bootstrap.php';

if (!killi_admin_password_configured() || !killi_is_admin_authenticated()) {
    header('Location: connections.php');
    exit;
}

$sources = killi_data_source_engine()->all();
$sourceId = $_REQUEST['source'] ?? killi_data_source_engine()->activeId();
$notice = null;
$editing = null; // null = list view, 'new' or a loaded record array = form view

// Fields the form manages directly; anything else round-trips through the
// "additional fields" textarea instead of needing a dedicated input.
const RECORD_FIELDS = [
    'title', 'description', 'sector', 'category', 'subcategory',
    'country', 'state', 'city', 'area', 'location', 'address',
    'phone', 'whatsapp', 'email', 'website', 'image', 'rating',
];
const RECORD_RESERVED = ['id', 'source_id', 'created_at', 'updated_at'];

if (killi_has_feature('crud')) {
    try {
        $crud = killi_crud_engine($sourceId);
    } catch (\InvalidArgumentException $e) {
        $notice = ['type' => 'error', 'text' => $e->getMessage()];
        $sourceId = killi_data_source_engine()->activeId();
        $crud = killi_crud_engine($sourceId);
    }

    $do = $_REQUEST['do'] ?? '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !killi_verify_csrf($_POST['csrf'] ?? '')) {
        $notice = ['type' => 'error', 'text' => 'Form expired — please reload and try again.'];
        $do = '';
    }

    if ($do === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = [];
        foreach (RECORD_FIELDS as $field) {
            if (isset($_POST[$field]) && trim($_POST[$field]) !== '') {
                $data[$field] = $field === 'rating' ? (float) $_POST[$field] : trim($_POST[$field]);
            }
        }
        $data['tags'] = array_values(array_filter(array_map('trim', explode(',', $_POST['tags'] ?? ''))));
        $data['verified'] = isset($_POST['verified']);
        $data['status'] = $_POST['status'] ?? 'active';

        $extraRaw = trim($_POST['extra_json'] ?? '');
        if ($extraRaw !== '') {
            $extra = json_decode($extraRaw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $notice = ['type' => 'error', 'text' => 'Additional fields must be valid JSON: ' . json_last_error_msg()];
            } elseif (is_array($extra)) {
                foreach (RECORD_RESERVED as $reserved) {
                    unset($extra[$reserved]);
                }
                $data = array_merge($data, $extra);
            }
        }

        if (!$notice) {
            try {
                $id = trim($_POST['id'] ?? '');
                if ($id !== '') {
                    $record = $crud->update($id, $data);
                    killi_record_audit('update', $sourceId, $id, $record['title'] ?? '');
                    $notice = ['type' => 'success', 'text' => 'Saved "' . $record['title'] . '".'];
                } else {
                    $record = $crud->create($data);
                    killi_record_audit('create', $sourceId, $record['id'], $record['title'] ?? '');
                    $notice = ['type' => 'success', 'text' => 'Created "' . $record['title'] . '".'];
                }
            } catch (\Throwable $e) {
                $notice = ['type' => 'error', 'text' => $e->getMessage()];
                $editing = array_merge($data, ['id' => $_POST['id'] ?? '']);
            }
        } else {
            $editing = array_merge($data, ['id' => $_POST['id'] ?? '']);
        }
    } elseif ($do === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = $_POST['id'] ?? '';
        $existing = $crud->get($id);
        try {
            $deleted = $existing && $crud->delete($id);
        } catch (\Throwable $e) {
            $notice = ['type' => 'error', 'text' => $e->getMessage()];
            $deleted = false;
        }
        if ($deleted) {
            killi_record_audit('delete', $sourceId, $id, $existing['title'] ?? '');
            $notice = ['type' => 'success', 'text' => 'Deleted "' . ($existing['title'] ?? $id) . '".'];
        } elseif (!$notice) {
            $notice = ['type' => 'error', 'text' => 'Could not delete that record.'];
        }
    }

    if ($editing === null && isset($_GET['edit'])) {
        if ($_GET['edit'] === 'new') {
            $editing = 'new';
        } else {
            $editing = $crud->get($_GET['edit']) ?? 'new';
        }
    }

    $query = trim($_GET['q'] ?? '');
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $listing = $crud->list($query, $page, 20);
}

$taxonomy = killi_read_json(__DIR__ . '/../data/taxonomy.json');
$sectorNames = array_column($taxonomy, 'sector');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>KilliSearch Ultra — Records</title>
<style>
  body { font-family: -apple-system, Arial, sans-serif; background: #f5f6f8; color: #202124; margin: 0; padding: 24px; }
  .wrap { max-width: 900px; margin: 0 auto; }
  h1 { font-size: 20px; }
  h2 { font-size: 15px; }
  .card { background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; padding: 16px; margin-bottom: 16px; }
  .notice { padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
  .notice.success { background: #e6f4ea; color: #137333; }
  .notice.error { background: #fce8e6; color: #c5221f; }
  .upsell { background: #fef8e8; border: 1px solid #e0b23d; border-radius: 10px; padding: 16px; font-size: 14px; }
  label { display: block; font-size: 12px; color: #5f6368; margin-top: 10px; }
  input, select, textarea { width: 100%; padding: 8px; border: 1px solid #d0d0d0; border-radius: 6px; font-size: 14px; box-sizing: border-box; font-family: inherit; }
  textarea { font-family: ui-monospace, monospace; font-size: 12px; }
  button { padding: 8px 14px; border: none; border-radius: 6px; background: #1a73e8; color: #fff; font-size: 14px; cursor: pointer; }
  button.secondary { background: #fff; color: #1a73e8; border: 1px solid #1a73e8; }
  button.danger { background: #fff; color: #c5221f; border: 1px solid #c5221f; }
  .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 16px; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 8px; }
  table th, table td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
  a.link { color: #1a73e8; text-decoration: none; }
  form.inline { display: inline; }
  .nav a { font-size: 13px; color: #5f6368; margin-right: 14px; text-decoration: none; }
  .toolbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
  .toolbar form { display: flex; gap: 8px; }
  .pager { display: flex; gap: 8px; margin-top: 12px; font-size: 13px; }
  .checkbox-row { display: flex; align-items: center; gap: 8px; margin-top: 10px; }
  .checkbox-row input { width: auto; }
</style>
</head>
<body>
<div class="wrap">
  <div style="display:flex;justify-content:space-between;align-items:baseline">
    <h1>Records</h1>
    <span class="nav">
      <a href="connections.php">Connections</a>
      <a href="backup.php">Backups</a>
      <a href="faq.php">FAQ</a>
    </span>
  </div>
  <p>Full create/read/update/delete over whichever data source you pick — the fourth pillar alongside Search, Conversation and Memory.</p>

  <?php if ($notice): ?>
    <div class="notice <?= $notice['type'] ?>"><?= htmlspecialchars($notice['text']) ?></div>
  <?php endif; ?>

  <?php if (!killi_has_feature('crud')): ?>
    <div class="upsell">Full record management is a Standard/Ultra feature. Activate a license key on the <a class="link" href="connections.php">Connections</a> page to unlock it — Free stays search-only.</div>
  <?php else: ?>

  <div class="card">
    <form method="get">
      <label>Data source</label>
      <select name="source" onchange="this.form.submit()">
        <?php foreach ($sources as $s): ?>
          <option value="<?= htmlspecialchars($s['id']) ?>" <?= $s['id'] === $sourceId ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?><?= ($s['type'] ?? 'json') === 'live_db' ? ' (live query)' : '' ?></option>
        <?php endforeach; ?>
      </select>
      <?php $selectedSource = array_values(array_filter($sources, fn($s) => $s['id'] === $sourceId))[0] ?? null; ?>
      <?php if (($selectedSource['type'] ?? 'json') === 'live_db'): ?>
        <p style="font-size:12px;color:#5f6368;margin-top:8px">This source queries "<?= htmlspecialchars($selectedSource['table']) ?>" live via "<?= htmlspecialchars($selectedSource['connection']) ?>"<?= empty($selectedSource['writable']) ? ' — read-only, so add/edit/delete are disabled below.' : ' — writable: edits/deletes below write straight back to this table.' ?></p>
      <?php endif; ?>
    </form>
  </div>

  <?php if ($editing !== null): ?>
    <?php $record = $editing === 'new' ? [] : $editing; ?>
    <div class="card">
      <h2 style="margin-top:0"><?= empty($record['id']) ? 'Add a new record' : 'Edit record' ?></h2>
      <form method="post" action="?do=save">
        <?= killi_csrf_field() ?>
        <input type="hidden" name="source" value="<?= htmlspecialchars($sourceId) ?>">
        <input type="hidden" name="id" value="<?= htmlspecialchars($record['id'] ?? '') ?>">

        <label>Title *</label>
        <input name="title" value="<?= htmlspecialchars($record['title'] ?? '') ?>" required>

        <label>Description</label>
        <textarea name="description" rows="2" style="font-family:inherit;font-size:14px"><?= htmlspecialchars($record['description'] ?? '') ?></textarea>

        <div class="grid2">
          <div>
            <label>Sector</label>
            <input name="sector" list="sector-list" value="<?= htmlspecialchars($record['sector'] ?? '') ?>">
            <datalist id="sector-list"><?php foreach ($sectorNames as $s): ?><option value="<?= htmlspecialchars($s) ?>"><?php endforeach; ?></datalist>
          </div>
          <div>
            <label>Category</label>
            <input name="category" value="<?= htmlspecialchars($record['category'] ?? '') ?>">
          </div>
        </div>
        <label>Subcategory</label>
        <input name="subcategory" value="<?= htmlspecialchars($record['subcategory'] ?? '') ?>">

        <div class="grid2">
          <div>
            <label>Country</label>
            <input name="country" value="<?= htmlspecialchars($record['country'] ?? '') ?>">
          </div>
          <div>
            <label>State</label>
            <input name="state" value="<?= htmlspecialchars($record['state'] ?? '') ?>">
          </div>
        </div>
        <div class="grid2">
          <div>
            <label>City</label>
            <input name="city" value="<?= htmlspecialchars($record['city'] ?? '') ?>">
          </div>
          <div>
            <label>Area</label>
            <input name="area" value="<?= htmlspecialchars($record['area'] ?? '') ?>">
          </div>
        </div>
        <label>Location (display label)</label>
        <input name="location" value="<?= htmlspecialchars($record['location'] ?? '') ?>">
        <label>Address</label>
        <input name="address" value="<?= htmlspecialchars($record['address'] ?? '') ?>">

        <div class="grid2">
          <div>
            <label>Phone</label>
            <input name="phone" value="<?= htmlspecialchars($record['phone'] ?? '') ?>">
          </div>
          <div>
            <label>WhatsApp</label>
            <input name="whatsapp" value="<?= htmlspecialchars($record['whatsapp'] ?? '') ?>">
          </div>
        </div>
        <div class="grid2">
          <div>
            <label>Email</label>
            <input type="email" name="email" value="<?= htmlspecialchars($record['email'] ?? '') ?>">
          </div>
          <div>
            <label>Website</label>
            <input name="website" value="<?= htmlspecialchars($record['website'] ?? '') ?>">
          </div>
        </div>
        <label>Image URL</label>
        <input name="image" value="<?= htmlspecialchars($record['image'] ?? '') ?>">

        <label>Tags (comma-separated)</label>
        <input name="tags" value="<?= htmlspecialchars(implode(', ', $record['tags'] ?? [])) ?>">

        <div class="grid2">
          <div>
            <label>Rating</label>
            <input type="number" step="0.1" min="0" max="5" name="rating" value="<?= htmlspecialchars((string) ($record['rating'] ?? '')) ?>">
          </div>
          <div>
            <label>Status</label>
            <select name="status">
              <option value="active" <?= ($record['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
              <option value="inactive" <?= ($record['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
          </div>
        </div>
        <div class="checkbox-row">
          <input type="checkbox" name="verified" id="verified" <?= !empty($record['verified']) ? 'checked' : '' ?>>
          <label for="verified" style="margin:0">Verified</label>
        </div>

        <label>Additional fields (JSON object, optional — for anything not covered above)</label>
        <textarea name="extra_json" rows="3" placeholder="{&quot;latitude&quot;: 6.45}"><?php
            $extra = array_diff_key($record, array_flip(array_merge(RECORD_FIELDS, RECORD_RESERVED, ['tags', 'verified', 'status'])));
            echo $extra ? htmlspecialchars(json_encode($extra, JSON_PRETTY_PRINT)) : '';
        ?></textarea>

        <div style="margin-top:16px;display:flex;gap:8px">
          <button type="submit">Save</button>
          <a href="?source=<?= urlencode($sourceId) ?>"><button type="button" class="secondary">Cancel</button></a>
        </div>
      </form>
    </div>
  <?php else: ?>

    <?php $isLiveSource = (($selectedSource['type'] ?? null) === 'live_db') && empty($selectedSource['writable']); ?>
    <div class="toolbar">
      <form method="get">
        <input type="hidden" name="source" value="<?= htmlspecialchars($sourceId) ?>">
        <input type="search" name="q" placeholder="Search this source…" value="<?= htmlspecialchars($query) ?>">
        <button type="submit" class="secondary">Search</button>
      </form>
      <?php if (!$isLiveSource): ?>
      <a href="?source=<?= urlencode($sourceId) ?>&amp;edit=new"><button type="button">Add a new record</button></a>
      <?php endif; ?>
    </div>

    <div class="card">
      <p style="font-size:13px;color:#5f6368;margin-top:0"><?= $listing['total'] ?> record<?= $listing['total'] === 1 ? '' : 's' ?> in this source<?= $query !== '' ? ' matching "' . htmlspecialchars($query) . '"' : '' ?>.</p>
      <table>
        <tr><th>Title</th><th>Sector / category</th><th>Status</th><th>Updated</th><th></th></tr>
        <?php foreach ($listing['records'] as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['title'] ?? '') ?></td>
          <td><?= htmlspecialchars(trim(($r['sector'] ?? '') . ' / ' . ($r['category'] ?? ''), ' /')) ?></td>
          <td><?= htmlspecialchars($r['status'] ?? '') ?><?= !empty($r['verified']) ? ' ✓' : '' ?></td>
          <td><?= htmlspecialchars(substr($r['updated_at'] ?? '', 0, 10)) ?></td>
          <td>
            <?php if ($isLiveSource): ?>
              <span style="color:#5f6368;font-size:12px">read-only</span>
            <?php else: ?>
            <a class="link" href="?source=<?= urlencode($sourceId) ?>&amp;edit=<?= urlencode($r['id']) ?>">Edit</a>
            &nbsp;
            <form class="inline" method="post" action="?do=delete" onsubmit="return confirm('Delete this record?')">
              <?= killi_csrf_field() ?>
              <input type="hidden" name="source" value="<?= htmlspecialchars($sourceId) ?>">
              <input type="hidden" name="id" value="<?= htmlspecialchars($r['id']) ?>">
              <button type="submit" class="danger">Delete</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$listing['records']): ?>
        <tr><td colspan="5" style="color:#5f6368">No records found.</td></tr>
        <?php endif; ?>
      </table>
      <?php $totalPages = max(1, (int) ceil($listing['total'] / $listing['perPage'])); ?>
      <?php if ($totalPages > 1): ?>
      <div class="pager">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
          <?php if ($p === $listing['page']): ?><strong><?= $p ?></strong><?php else: ?>
          <a class="link" href="?source=<?= urlencode($sourceId) ?>&amp;q=<?= urlencode($query) ?>&amp;page=<?= $p ?>"><?= $p ?></a>
          <?php endif; ?>
        <?php endfor; ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2 style="margin-top:0">Export this source</h2>
      <a class="link" href="../api/export.php?source=<?= urlencode($sourceId) ?>&amp;format=json">Download JSON</a>
      &nbsp;·&nbsp;
      <a class="link" href="../api/export.php?source=<?= urlencode($sourceId) ?>&amp;format=csv">Download CSV</a>
    </div>

  <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>
