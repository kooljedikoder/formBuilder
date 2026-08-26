<?php

require_once __DIR__ . '/../bootstrap.php';

/**
 * A standalone try-it page for evaluators/prospective buyers — never
 * linked from the real customer-facing portal. Clicking a tier sets the
 * same $_SESSION['killi_host_user'] override host apps use for identity
 * handoff (see killi_current_package()'s docblock), so "try Standard"
 * genuinely runs the app under that package's real feature gates rather
 * than a separate mocked-up experience. Exiting just clears that
 * override — nothing here touches real licensing/admin state.
 */

killi_ensure_session();

$entitlements = killi_entitlement_manager();
$packagesConfig = killi_read_json(__DIR__ . '/../config/packages.json');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['tier'])) {
    $tier = $_GET['tier'];
    if (in_array($tier, $entitlements->packageIds(), true)) {
        $_SESSION['killi_host_user'] = ['user_id' => 'demo-guest', 'package' => $tier];
        header('Location: index.php');
        exit;
    }
}

$FEATURE_LABELS = [
    'search' => 'Search &amp; chat',
    'forms' => 'Multi-step enquiry forms',
    'memory' => 'Memory / FAQ recall',
    'attachments' => 'File attachments in chat',
    'crud' => 'Records (CRUD) &amp; result layouts',
    'import' => 'CSV/JSON import',
    'db_connections' => 'Live database connections',
    'multi_source' => 'Multiple data sources',
    'white_label' => 'Remove &quot;Powered by Killi&quot;',
];

$branding = killi_branding();
$colors = $branding['colors'] ?? [];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Try Killi — Free, Standard, Ultra</title>
<style>
  body { font-family: -apple-system, Arial, sans-serif; background: #f5f6f8; color: #202124; margin: 0; padding: 32px 20px 60px; }
  .wrap { max-width: 900px; margin: 0 auto; }
  h1 { font-size: 24px; margin-bottom: 4px; }
  .lede { color: #5f6368; font-size: 14px; margin-bottom: 28px; max-width: 60ch; }
  .tiers { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
  @media (max-width: 720px) { .tiers { grid-template-columns: 1fr; } }
  .tier-card { background: #fff; border: 1px solid #e0e0e0; border-radius: 12px; padding: 20px; display: flex; flex-direction: column; }
  .tier-card.ultra { border-color: <?= htmlspecialchars($colors['primary'] ?? '#1a73e8') ?>; box-shadow: 0 2px 10px rgba(26,115,232,0.12); }
  .tier-name { font-size: 18px; font-weight: 700; margin: 0 0 4px; }
  .tier-sub { font-size: 12.5px; color: #5f6368; margin: 0 0 16px; }
  .tier-features { list-style: none; padding: 0; margin: 0 0 20px; font-size: 13.5px; flex: 1; }
  .tier-features li { padding: 5px 0; display: flex; align-items: flex-start; gap: 8px; }
  .tier-features li.included { color: #202124; }
  .tier-features li.excluded { color: #bdc1c6; }
  .tier-features .mark { flex: none; font-weight: 700; }
  .tier-features li.included .mark { color: #0f9d58; }
  .try-btn { display: block; text-align: center; text-decoration: none; padding: 10px 14px; border-radius: 8px; font-size: 14px; font-weight: 600; background: <?= htmlspecialchars($colors['primary'] ?? '#1a73e8') ?>; color: #fff; }
  .try-btn.secondary { background: #fff; color: <?= htmlspecialchars($colors['primary'] ?? '#1a73e8') ?>; border: 1px solid <?= htmlspecialchars($colors['primary'] ?? '#1a73e8') ?>; }
  .note { margin-top: 24px; font-size: 12.5px; color: #5f6368; max-width: 60ch; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Try Killi</h1>
  <p class="lede">Pick a tier to explore it as a guest — no account, no setup. This runs the real app under that tier's actual feature gates, the same as a real customer would see it.</p>

  <div class="tiers">
    <?php foreach ($packagesConfig['packages'] ?? [] as $pkg): ?>
      <?php $features = $pkg['features'] ?? []; ?>
      <div class="tier-card <?= htmlspecialchars($pkg['id']) ?>">
        <p class="tier-name"><?= htmlspecialchars($pkg['name']) ?></p>
        <p class="tier-sub"><?= count($features) ?> feature<?= count($features) === 1 ? '' : 's' ?> unlocked</p>
        <ul class="tier-features">
          <?php foreach ($FEATURE_LABELS as $key => $label): ?>
            <?php $has = in_array($key, $features, true); ?>
            <li class="<?= $has ? 'included' : 'excluded' ?>"><span class="mark"><?= $has ? '✓' : '—' ?></span> <?= $label ?></li>
          <?php endforeach; ?>
        </ul>
        <a class="try-btn <?= $pkg['id'] === 'ultra' ? '' : 'secondary' ?>" href="?tier=<?= htmlspecialchars($pkg['id']) ?>">Try <?= htmlspecialchars($pkg['name']) ?> as guest &rarr;</a>
      </div>
    <?php endforeach; ?>
  </div>

  <p class="note">This is a demo session only (set via the same host-app identity hook a real integration would use) — it doesn't create an admin account, doesn't touch licensing, and doesn't persist anything once you close the tab.</p>
</div>
</body>
</html>
