<?php

/**
 * Shared admin chrome — header, theme toggle, desktop pill nav, mobile
 * bottom tab bar + "More" sheet. Every admin/*.php page already used the
 * identical class vocabulary (.card/.notice/.upsell/button/table/...) in
 * its own copy-pasted <style> block; assets/css/admin.css now owns that
 * once, and this file owns the surrounding chrome, so a page only needs
 * to open/close it and keep its own body markup and page-specific extras.
 */

const KILLI_ADMIN_NAV_ITEMS = [
    ['id' => 'dashboard', 'label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'home', 'primary' => true],
    ['id' => 'records', 'label' => 'Records', 'href' => 'records.php', 'icon' => 'grid', 'primary' => true],
    ['id' => 'faq', 'label' => 'FAQ', 'href' => 'faq.php', 'icon' => 'help', 'primary' => true],
    ['id' => 'feedback', 'label' => 'Feedback', 'href' => 'feedback.php', 'icon' => 'star', 'primary' => true],
    ['id' => 'connections', 'label' => 'Connections', 'href' => 'connections.php', 'icon' => 'plug', 'primary' => false],
    ['id' => 'backup', 'label' => 'Backups', 'href' => 'backup.php', 'icon' => 'archive', 'primary' => false],
    ['id' => 'setup', 'label' => 'Setup', 'href' => 'setup.php', 'icon' => 'sliders', 'primary' => false],
];

function killi_admin_icon(string $name): string
{
    $icons = [
        'home' => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 0 1 4.7 1.2c0 1.6-2.2 1.8-2.2 3.5"/><line x1="12" y1="17" x2="12" y2="17.1"/>',
        'star' => '<path d="M12 3l2.6 5.6 6.1.6-4.6 4.1 1.3 6-5.4-3.1-5.4 3.1 1.3-6-4.6-4.1 6.1-.6L12 3Z"/>',
        'plug' => '<path d="M9 3v5"/><path d="M15 3v5"/><path d="M6 8h12v4a6 6 0 0 1-12 0V8Z"/><path d="M12 18v3"/>',
        'archive' => '<rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8"/><line x1="10" y1="13" x2="14" y2="13"/>',
        'sliders' => '<line x1="4" y1="6" x2="20" y2="6"/><circle cx="9" cy="6" r="2"/><line x1="4" y1="12" x2="20" y2="12"/><circle cx="15" cy="12" r="2"/><line x1="4" y1="18" x2="20" y2="18"/><circle cx="7" cy="18" r="2"/>',
        'more' => '<circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/>',
        'sun' => '<circle cx="12" cy="12" r="4.5"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
        'moon' => '<path d="M20 14.5A8.5 8.5 0 1 1 9.5 4a7 7 0 0 0 10.5 10.5Z"/>',
        'x' => '<line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/>',
        'guest' => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-4 3-6 7-6s7 2 7 6"/>',
        'logout' => '<path d="M15 4H8a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h7"/><path d="M11 12h9m0 0-3.5-3.5M20 12l-3.5 3.5"/>',
        'plus' => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'upload' => '<path d="M12 16V4"/><path d="M7 9l5-5 5 5"/><path d="M5 20h14"/>',
    ];
    $body = $icons[$name] ?? $icons['help'];
    return '<svg viewBox="0 0 24 24">' . $body . '</svg>';
}

function killi_admin_head(string $title): void
{
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title) ?> — Killi Admin</title>
<link rel="stylesheet" href="../assets/css/admin.css">
</head>
<?php
}

/** @param string $active one of KILLI_ADMIN_NAV_ITEMS ids */
function killi_admin_body_open(string $active): void
{
    $username = killi_current_admin_username();
    $role = killi_current_admin_role();
    $primary = array_values(array_filter(KILLI_ADMIN_NAV_ITEMS, fn($i) => $i['primary']));
    $secondary = array_values(array_filter(KILLI_ADMIN_NAV_ITEMS, fn($i) => !$i['primary']));
    $moreIsActive = in_array($active, array_column($secondary, 'id'), true);
    ?>
<body>
<div class="admin-shell">
  <header class="admin-topbar">
    <a class="admin-brand" href="dashboard.php"><span class="admin-brand-mark">K</span> Killi Admin</a>
    <nav class="admin-topnav">
      <?php foreach (KILLI_ADMIN_NAV_ITEMS as $item): ?>
        <a class="admin-pill <?= $item['id'] === $active ? 'active' : '' ?>" href="<?= htmlspecialchars($item['href']) ?>"><?= htmlspecialchars($item['label']) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="admin-actions">
      <?php if ($username): ?>
      <span class="admin-who"><?= htmlspecialchars($username) ?><?php if ($role): ?><span class="role-pill"><?= htmlspecialchars($role) ?></span><?php endif; ?></span>
      <?php endif; ?>
      <button type="button" id="admin-theme-toggle" class="admin-icon-btn" aria-label="Toggle dark mode">
        <?= killi_admin_icon('sun') ?>
      </button>
      <a class="admin-icon-btn" href="connections.php?do=admin_logout" aria-label="Log out"><?= killi_admin_icon('logout') ?></a>
    </div>
  </header>

  <nav class="admin-bottomnav">
    <?php foreach ($primary as $item): ?>
      <a class="admin-tab <?= $item['id'] === $active ? 'active' : '' ?>" href="<?= htmlspecialchars($item['href']) ?>">
        <?= killi_admin_icon($item['icon']) ?>
        <span><?= htmlspecialchars($item['label']) ?></span>
      </a>
    <?php endforeach; ?>
    <button type="button" class="admin-tab <?= $moreIsActive ? 'active' : '' ?>" id="admin-more-btn">
      <?= killi_admin_icon('more') ?>
      <span>More</span>
    </button>
  </nav>

  <div class="admin-more-sheet" id="admin-more-sheet" hidden>
    <div class="admin-more-card">
      <div class="admin-more-title">More</div>
      <?php foreach ($secondary as $item): ?>
        <a href="<?= htmlspecialchars($item['href']) ?>"><?= killi_admin_icon($item['icon']) ?> <?= htmlspecialchars($item['label']) ?></a>
      <?php endforeach; ?>
      <a href="#" id="admin-more-close"><?= killi_admin_icon('x') ?> Close</a>
    </div>
  </div>

  <main class="admin-main">
    <div class="wrap">
    <?php
}

function killi_admin_body_close(): void
{
    ?>
    </div>
  </main>
</div>
<script src="../assets/js/admin.js"></script>
</body>
</html>
<?php
}
