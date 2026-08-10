<?php

require_once __DIR__ . '/../bootstrap.php';

killi_ensure_session();
if (($_GET['exit_demo'] ?? '') === '1') {
    unset($_SESSION['killi_host_user']);
    header('Location: demo.php');
    exit;
}
$isDemoGuest = ($_SESSION['killi_host_user']['user_id'] ?? '') === 'demo-guest';

$branding = killi_branding();
$colors = $branding['colors'] ?? [];
$authError = null;

if (killi_app_password_configured()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['app_password'])) {
        if (killi_verify_app_password($_POST['app_password'])) {
            killi_set_app_authenticated(true);
        } else {
            $authError = 'Incorrect password.';
        }
    }

    if (!killi_is_app_authenticated()) {
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

$sectors = array_column(killi_taxonomy_engine()->tree(), 'sector');
$hasAttachments = killi_has_feature('attachments');
$hasWhiteLabel = killi_has_feature('white_label');
// The PWA (installable app, manifest, service worker) is a standalone-only
// feature — a host app embedding this page (an iframe, a shared layout)
// has its own wrapper and shouldn't have Killi offering to install itself
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
<link rel="stylesheet" href="../assets/css/killi.css">
<style>
:root {
  --killi-primary: <?= htmlspecialchars($colors['primary'] ?? '#1a73e8') ?>;
  --killi-secondary: <?= htmlspecialchars($colors['secondary'] ?? '#0f9d58') ?>;
  --killi-accent: <?= htmlspecialchars($colors['accent'] ?? '#fbbc04') ?>;
  --killi-bg: <?= htmlspecialchars($colors['background'] ?? '#ffffff') ?>;
  --killi-text: <?= htmlspecialchars($colors['text'] ?? '#202124') ?>;
  --killi-chat-header: <?= htmlspecialchars($colors['chat_header'] ?? '#1a73e8') ?>;
  --killi-chat-bg: <?= htmlspecialchars($colors['chat_background'] ?? '#f8f9fa') ?>;
  --killi-user-bubble: <?= htmlspecialchars($colors['user_bubble'] ?? '#1a73e8') ?>;
  --killi-ai-bubble: <?= htmlspecialchars($colors['ai_bubble'] ?? '#ffffff') ?>;
}
</style>
</head>
<body>
<?php if ($isDemoGuest): ?>
<div style="background:#202124;color:#fff;font-size:12.5px;padding:6px 12px;text-align:center">
  Demo mode — browsing as <strong><?= htmlspecialchars(ucfirst(killi_current_package())) ?></strong>
  <a href="?exit_demo=1" style="color:#8ab4f8;margin-left:8px">Exit demo</a>
</div>
<?php endif; ?>
<div id="killi-app" class="killi-app">
  <header class="killi-header">
    <div class="killi-header-title"><?= htmlspecialchars($branding['product_name'] ?? 'KilliGoogle.ai') ?></div>
    <div style="display:flex;gap:8px">
      <?php if (!$isEmbedded): ?>
      <button id="killi-install" class="killi-theme-toggle" type="button" aria-label="Install app" hidden>&#8615;</button>
      <?php endif; ?>
      <button id="killi-rate" class="killi-theme-toggle" type="button" aria-label="Rate this conversation" hidden><svg viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.27 5.82 21 7 14.14l-5-4.87 6.91-1.01L12 2Z"/></svg></button>
      <button id="killi-theme-toggle" class="killi-theme-toggle" type="button" aria-label="Toggle dark mode">&#127769;</button>
    </div>
  </header>

  <div class="killi-chat" id="killi-chat"></div>
  <div class="killi-rate-panel" id="killi-rate-panel" hidden>
    <div class="killi-rate-stars" id="killi-rate-stars">
      <button type="button" class="killi-star" data-value="1" aria-label="1 star">&#9733;</button>
      <button type="button" class="killi-star" data-value="2" aria-label="2 stars">&#9733;</button>
      <button type="button" class="killi-star" data-value="3" aria-label="3 stars">&#9733;</button>
      <button type="button" class="killi-star" data-value="4" aria-label="4 stars">&#9733;</button>
      <button type="button" class="killi-star" data-value="5" aria-label="5 stars">&#9733;</button>
    </div>
    <textarea id="killi-rate-comment" class="killi-rate-comment" placeholder="Anything we should know? (optional)" rows="2"></textarea>
    <div class="killi-rate-actions">
      <button type="button" class="killi-rate-cancel" id="killi-rate-cancel">Cancel</button>
      <button type="button" class="killi-rate-submit" id="killi-rate-submit" disabled>Submit</button>
    </div>
  </div>

  <div class="killi-chips" id="killi-chips">
    <?php foreach ($sectors as $sector): ?>
      <button class="killi-chip" data-sector="<?= htmlspecialchars($sector) ?>"><?= htmlspecialchars($sector) ?></button>
    <?php endforeach; ?>
  </div>

  <form class="killi-searchbar" id="killi-searchbar" autocomplete="off">
    <?php if ($hasAttachments): ?>
    <button type="button" id="killi-attach" class="killi-icon-btn" aria-label="Attach a file">&#128206;</button>
    <input type="file" id="killi-attach-camera-photo" accept="image/jpeg,image/png,image/webp" capture="environment" hidden>
    <input type="file" id="killi-attach-camera-video" accept="video/mp4,video/quicktime,video/webm" capture="environment" hidden>
    <input type="file" id="killi-attach-input" accept="image/jpeg,image/png,image/gif,image/webp,application/pdf" hidden>
    <?php endif; ?>
    <button type="button" id="killi-mic" class="killi-icon-btn" aria-label="Speak your search" hidden>&#127908;</button>
    <button type="button" id="killi-filter" class="killi-icon-btn" aria-label="Advanced search"><svg viewBox="0 0 24 24"><line x1="4" y1="6" x2="20" y2="6"/><circle cx="9" cy="6" r="2"/><line x1="4" y1="12" x2="20" y2="12"/><circle cx="15" cy="12" r="2"/><line x1="4" y1="18" x2="20" y2="18"/><circle cx="9" cy="18" r="2"/></svg></button>
    <input
      type="search"
      id="killi-input"
      class="killi-input"
      placeholder="<?= htmlspecialchars($branding['search_placeholder'] ?? 'Search...') ?>"
      aria-label="Search"
    >
    <button type="submit" class="killi-send" aria-label="Search">&#8593;</button>
  </form>
  <div id="killi-suggestions" class="killi-suggestions" hidden></div>
  <?php if (!$hasWhiteLabel): ?>
  <div class="killi-attribution">Powered by Killi</div>
  <?php endif; ?>
  <nav class="killi-bottom-nav" id="killi-bottom-nav">
    <button type="button" class="killi-nav-item active" data-nav="home">
      <span class="killi-nav-icon"><svg viewBox="0 0 24 24"><path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/></svg></span>
      <span class="killi-nav-label">Home</span>
    </button>
    <button type="button" class="killi-nav-item" data-nav="search">
      <span class="killi-nav-icon"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></span>
      <span class="killi-nav-label">Search</span>
    </button>
    <button type="button" class="killi-nav-item" data-nav="saved">
      <span class="killi-nav-icon"><svg viewBox="0 0 24 24"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.8 1-1a5.5 5.5 0 0 0 0-7.6Z"/></svg></span>
      <span class="killi-nav-label">Saved</span>
    </button>
    <button type="button" class="killi-nav-item" data-nav="profile">
      <span class="killi-nav-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-7 8-7s8 3 8 7"/></svg></span>
      <span class="killi-nav-label">Profile</span>
    </button>
  </nav>
  <?php if ($hasAttachments): ?>
  <div class="killi-attach-sheet" id="killi-attach-sheet" hidden>
    <div class="killi-attach-sheet-card">
      <button type="button" class="killi-attach-option" data-target="killi-attach-camera-photo">&#128247; Take Photo</button>
      <button type="button" class="killi-attach-option" data-target="killi-attach-camera-video">&#127909; Record Video</button>
      <button type="button" class="killi-attach-option" data-target="killi-attach-input">&#128193; Choose File</button>
      <button type="button" class="killi-attach-option killi-attach-cancel" id="killi-attach-cancel">Cancel</button>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
window.KILLI_BRANDING = <?= json_encode($branding) ?>;
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
  var installBtn = document.getElementById('killi-install');
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
<script src="../assets/js/killi.js"></script>
</body>
</html>
