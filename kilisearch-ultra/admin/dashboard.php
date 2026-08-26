<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_chrome.php';

if (!killi_admin_password_configured() || !killi_is_admin_authenticated()) {
    header('Location: connections.php');
    exit;
}

$tier = killi_current_package();
$entitlements = killi_entitlement_manager();
$featureCount = count($entitlements->features($tier));

$sources = killi_data_source_engine()->all();
$activeSourceId = killi_data_source_engine()->activeId();
$sourceCounts = [];
$totalRecords = 0;
foreach ($sources as $s) {
    try {
        $count = killi_crud_engine($s['id'])->list('', 1, 1)['total'];
    } catch (\Throwable $e) {
        $count = null; // live source unreachable — show as "—", not a crash
    }
    $sourceCounts[$s['id']] = $count;
    if (is_int($count)) {
        $totalRecords += $count;
    }
}

$faqMode = killi_data_source_engine()->faqConfig()['mode'] ?? 'untied';
try {
    $faqCount = count(killi_faq_storage()->all());
} catch (\Throwable $e) {
    $faqCount = null;
}

$sessionRatings = killi_read_json(__DIR__ . '/../data/session_feedback.json');
$avgRating = null;
if ($sessionRatings) {
    $avgRating = array_sum(array_column($sessionRatings, 'rating')) / count($sessionRatings);
}

$dailySearches = killi_daily_query_counts(14);
$searchesToday = end($dailySearches) ?: 0;
$searchesThisWindow = array_sum($dailySearches);

$tierLabels = ['free' => 'Free', 'standard' => 'Standard', 'ultra' => 'Ultra'];

killi_admin_head('Dashboard');
killi_admin_body_open('dashboard');
?>

<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:16px">
  <div>
    <h1>Dashboard</h1>
    <p class="lead">A quick read on data, memory and how visitors are reacting — before you go dig into a specific screen.</p>
  </div>
  <span class="admin-tier-pill <?= htmlspecialchars($tier) ?>"><?= htmlspecialchars($tierLabels[$tier] ?? $tier) ?> · <?= $featureCount ?> feature<?= $featureCount === 1 ? '' : 's' ?></span>
</div>

<?php if ($tier === 'free'): ?>
<div class="upsell" style="margin-bottom:16px">
  Free is search-only. Activate a license key on <a class="link" href="setup.php?step=2">Setup</a> to unlock Records, Memory/FAQ, attachments and multi-source — or open <a class="link" href="../portal/demo.php" target="_blank" rel="noopener">Try Killi</a> to preview Standard/Ultra first.
</div>
<?php endif; ?>

<div class="admin-stat-grid" style="margin-bottom:16px">
  <div class="admin-stat">
    <div class="num"><?= $totalRecords ?></div>
    <div class="label">Records across <?= count($sources) ?> source<?= count($sources) === 1 ? '' : 's' ?></div>
  </div>
  <div class="admin-stat">
    <div class="num"><?= $faqCount ?? '—' ?></div>
    <div class="label">FAQ entries (<?= $faqMode === 'tied' ? 'tied to a live table' : 'local' ?>)</div>
  </div>
  <div class="admin-stat">
    <div class="num"><?= $avgRating !== null ? number_format($avgRating, 1) . '★' : '—' ?></div>
    <div class="label"><?= count($sessionRatings) ?> end-of-chat rating<?= count($sessionRatings) === 1 ? '' : 's' ?></div>
  </div>
  <div class="admin-stat">
    <div class="num"><?= $searchesToday ?></div>
    <div class="label">Searches today</div>
  </div>
</div>

<div class="card">
  <h2 style="margin-top:0">Search volume — last 14 days (<?= $searchesThisWindow ?> total)</h2>
  <?= killi_admin_sparkline($dailySearches) ?>
</div>

<div class="card">
  <h2 style="margin-top:0">Quick actions</h2>
  <div class="admin-quick-row">
    <a class="admin-pill-action" href="records.php?edit=new&source=<?= htmlspecialchars($activeSourceId ?? '') ?>"><?= killi_admin_icon('plus') ?> Add a record</a>
    <a class="admin-pill-action" href="records.php"><?= killi_admin_icon('upload') ?> Import data</a>
    <a class="admin-pill-action" href="feedback.php"><?= killi_admin_icon('star') ?> Review feedback</a>
    <a class="admin-pill-action" href="../portal/demo.php" target="_blank" rel="noopener"><?= killi_admin_icon('guest') ?> Try as guest</a>
  </div>
</div>

<div class="card">
  <h2 style="margin-top:0">Data sources</h2>
  <?php if (!$sources): ?>
    <p class="admin-empty">No data sources configured yet.</p>
  <?php else: ?>
    <?php foreach ($sources as $s): ?>
      <div class="admin-source-row">
        <span><?= htmlspecialchars($s['name']) ?><?= $s['id'] === $activeSourceId ? ' <span class="role-pill" style="background:var(--admin-primary-wash);color:var(--admin-primary);padding:2px 7px;border-radius:999px;font-size:10.5px;margin-left:6px">ACTIVE</span>' : '' ?><?= ($s['type'] ?? 'json') === 'live_db' ? ' <span style="color:var(--admin-muted);font-size:12px">(live query)</span>' : '' ?></span>
        <span class="count"><?= $sourceCounts[$s['id']] ?? '—' ?></span>
      </div>
    <?php endforeach; ?>
    <p style="margin:12px 0 0"><a class="link" href="records.php">Manage records &rarr;</a></p>
  <?php endif; ?>
</div>

<div class="card">
  <h2 style="margin-top:0">Latest feedback</h2>
  <?php if (!$sessionRatings): ?>
    <p class="admin-empty">No end-of-chat ratings yet — they'll show up here once visitors start rating conversations.</p>
  <?php else: ?>
    <?php $latest = $sessionRatings[count($sessionRatings) - 1]; ?>
    <p style="margin:0 0 4px"><span style="color:#f5b400;letter-spacing:1px"><?= str_repeat('★', (int) ($latest['rating'] ?? 5)) . str_repeat('☆', 5 - (int) ($latest['rating'] ?? 5)) ?></span></p>
    <?php if (!empty($latest['comment'])): ?><p style="font-size:13.5px;margin:4px 0"><?= htmlspecialchars($latest['comment']) ?></p><?php endif; ?>
    <p style="margin:8px 0 0"><a class="link" href="feedback.php">See all feedback &rarr;</a></p>
  <?php endif; ?>
</div>

<?php
killi_admin_body_close();
