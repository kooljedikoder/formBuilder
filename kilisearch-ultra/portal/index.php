<?php

require_once __DIR__ . '/../bootstrap.php';

$branding = kili_branding();
$colors = $branding['colors'] ?? [];
$authError = null;

if (kili_app_password_configured()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['app_password'])) {
        if (kili_verify_app_password($_POST['app_password'])) {
            kili_set_app_authenticated(true);
        } else {
            $authError = 'Incorrect password.';
        }
    }

    if (!kili_is_app_authenticated()) {
        ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($branding['product_name'] ?? 'KilliGoogle.ai') ?></title>
<style>
  body { font-family: -apple-system, Arial, sans-serif; background: <?= htmlspecialchars($colors['chat_header'] ?? '#1a73e8') ?>; color: #202124; margin: 0; height: 100vh; display: flex; align-items: center; justify-content: center; }
  .gate { background: #fff; border-radius: 14px; padding: 28px; width: 90%; max-width: 340px; text-align: center; }
  .gate h1 { font-size: 18px; margin: 0 0 4px; }
  .gate p { font-size: 13px; color: #5f6368; margin: 0 0 16px; }
  .gate input { width: 100%; padding: 12px; border: 1px solid #d0d0d0; border-radius: 8px; font-size: 15px; box-sizing: border-box; }
  .gate button { width: 100%; margin-top: 10px; padding: 12px; border: none; border-radius: 8px; background: <?= htmlspecialchars($colors['primary'] ?? '#1a73e8') ?>; color: #fff; font-size: 15px; cursor: pointer; }
  .gate .error { color: #c5221f; font-size: 13px; margin-top: 10px; }
</style>
</head>
<body>
  <form class="gate" method="post">
    <h1><?= htmlspecialchars($branding['product_name'] ?? 'KilliGoogle.ai') ?></h1>
    <p>This app is password-protected.</p>
    <input type="password" name="app_password" placeholder="Password" autofocus required>
    <button type="submit">Unlock</button>
    <?php if ($authError): ?><div class="error"><?= htmlspecialchars($authError) ?></div><?php endif; ?>
  </form>
</body>
</html>
        <?php
        exit;
    }
}

$sectors = array_column(kili_taxonomy_engine()->tree(), 'sector');
$hasAttachments = kili_has_feature('attachments');
$hasWhiteLabel = kili_has_feature('white_label');
// The PWA (installable app, manifest, service worker) is a standalone-only
// feature — a host app embedding this page (an iframe, a shared layout)
// has its own wrapper and shouldn't have Kili offering to install itself
// as a separate app on top of it. A real integration signals that by
// linking/iframing with ?embed=1; its absence means "this is being opened
// as its own page," which covers both a fully standalone deployment and a
// host app that links out to a full standalone tab (see examples/host-app-demo.php).
$isEmbedded = ($_GET['embed'] ?? '') === '1';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= htmlspecialchars($branding['product_name'] ?? 'KilliGoogle.ai') ?></title>
<?php if (!$isEmbedded): ?>
<link rel="manifest" href="manifest.php">
<link rel="icon" href="icon.php?size=192">
<link rel="apple-touch-icon" href="icon.php?size=192">
<meta name="theme-color" content="<?= htmlspecialchars($colors['chat_header'] ?? '#1a73e8') ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars(mb_substr($branding['product_name'] ?? 'Killi', 0, 12)) ?>">
<?php endif; ?>
<link rel="stylesheet" href="../assets/css/kili.css">
<style>
:root {
  --kili-primary: <?= htmlspecialchars($colors['primary'] ?? '#1a73e8') ?>;
  --kili-secondary: <?= htmlspecialchars($colors['secondary'] ?? '#0f9d58') ?>;
  --kili-accent: <?= htmlspecialchars($colors['accent'] ?? '#fbbc04') ?>;
  --kili-bg: <?= htmlspecialchars($colors['background'] ?? '#ffffff') ?>;
  --kili-text: <?= htmlspecialchars($colors['text'] ?? '#202124') ?>;
  --kili-chat-header: <?= htmlspecialchars($colors['chat_header'] ?? '#1a73e8') ?>;
  --kili-chat-bg: <?= htmlspecialchars($colors['chat_background'] ?? '#f8f9fa') ?>;
  --kili-user-bubble: <?= htmlspecialchars($colors['user_bubble'] ?? '#1a73e8') ?>;
  --kili-ai-bubble: <?= htmlspecialchars($colors['ai_bubble'] ?? '#ffffff') ?>;
}
</style>
</head>
<body>
<div id="kili-app" class="kili-app">
  <header class="kili-header">
    <div class="kili-header-title"><?= htmlspecialchars($branding['product_name'] ?? 'KilliGoogle.ai') ?></div>
    <div style="display:flex;gap:8px">
      <?php if (!$isEmbedded): ?>
      <button id="kili-install" class="kili-theme-toggle" type="button" aria-label="Install app" hidden>&#8615;</button>
      <?php endif; ?>
      <button id="kili-theme-toggle" class="kili-theme-toggle" type="button" aria-label="Toggle dark mode">&#127769;</button>
    </div>
  </header>

  <div class="kili-chat" id="kili-chat"></div>

  <div class="kili-chips" id="kili-chips">
    <?php foreach ($sectors as $sector): ?>
      <button class="kili-chip" data-sector="<?= htmlspecialchars($sector) ?>"><?= htmlspecialchars($sector) ?></button>
    <?php endforeach; ?>
  </div>

  <form class="kili-searchbar" id="kili-searchbar" autocomplete="off">
    <?php if ($hasAttachments): ?>
    <button type="button" id="kili-attach" class="kili-icon-btn" aria-label="Attach a file">&#128206;</button>
    <input type="file" id="kili-attach-input" accept="image/jpeg,image/png,image/gif,image/webp,application/pdf" hidden>
    <?php endif; ?>
    <button type="button" id="kili-mic" class="kili-icon-btn" aria-label="Speak your search" hidden>&#127908;</button>
    <input
      type="search"
      id="kili-input"
      class="kili-input"
      placeholder="<?= htmlspecialchars($branding['search_placeholder'] ?? 'Search...') ?>"
      aria-label="Search"
    >
    <button type="submit" class="kili-send" aria-label="Search">&#8593;</button>
  </form>
  <div id="kili-suggestions" class="kili-suggestions" hidden></div>
  <?php if (!$hasWhiteLabel): ?>
  <div class="kili-attribution">Powered by Killi</div>
  <?php endif; ?>
</div>

<script>
window.KILI_BRANDING = <?= json_encode($branding) ?>;
</script>
<?php if (!$isEmbedded): ?>
<script>
// Standalone-only: a host app embedding this page owns its own PWA/wrapper
// story, so neither the service worker nor the install prompt run there.
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('sw.js').catch(function () {});
  });
}

(function () {
  var installBtn = document.getElementById('kili-install');
  if (!installBtn) return;
  var deferredPrompt = null;

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferredPrompt = e;
    installBtn.hidden = false;
  });

  installBtn.addEventListener('click', function () {
    if (!deferredPrompt) return;
    deferredPrompt.prompt();
    deferredPrompt.userChoice.then(function () {
      deferredPrompt = null;
      installBtn.hidden = true;
    });
  });

  window.addEventListener('appinstalled', function () {
    installBtn.hidden = true;
  });
})();
</script>
<?php endif; ?>
<script src="../assets/js/kili.js"></script>
</body>
</html>
