<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_chrome.php';

// This wizard is part of the admin surface, so it needs the same admin
// gate as connections.php — but it must never become a second setup path.
// If no admin password exists yet, send the visitor to connections.php,
// which owns creating that password; it will bounce back here afterwards.
if (!killi_admin_password_configured() || !killi_is_admin_authenticated()) {
    header('Location: connections.php');
    exit;
}

// The very first run (fresh install) is always by the owner just created in
// admin_setup. Re-running the wizard afterward touches licensing/app-access —
// owner-only — so an editor has no legitimate reason to be here.
if (killi_setup_complete() && !killi_is_admin_owner()) {
    header('Location: connections.php');
    exit;
}

$step = (int) ($_GET['step'] ?? 1);
if ($step < 1 || $step > 3) {
    $step = 1;
}
$notice = null;
$do = $_REQUEST['do'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !killi_verify_csrf($_POST['csrf'] ?? '')) {
    $notice = ['type' => 'error', 'text' => 'Form expired — please reload and try again.'];
    $do = '';
}

if ($do === 'activate_license' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $key = trim($_POST['license_key'] ?? '');
    if ($key === '') {
        header('Location: setup.php?step=2');
        exit;
    }
    $result = killi_activate_license($key);
    if ($result['success']) {
        header('Location: setup.php?step=2');
        exit;
    }
    $notice = ['type' => 'error', 'text' => $result['message']];
} elseif ($do === 'skip_license') {
    header('Location: setup.php?step=2');
    exit;
} elseif ($do === 'set_app_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = trim($_POST['app_password'] ?? '');
    if ($password !== '') {
        killi_save_env_value('KILLI_APP_PASSWORD_HASH', password_hash($password, PASSWORD_DEFAULT));
    }
    header('Location: setup.php?step=3');
    exit;
} elseif ($do === 'skip_app_password') {
    header('Location: setup.php?step=3');
    exit;
} elseif ($do === 'finish') {
    killi_mark_setup_complete();
    header('Location: connections.php');
    exit;
}

$branding = killi_branding();
$productName = $branding['product_name'] ?? 'KilliGoogle.ai';
$currentPackage = killi_current_package();
$packageName = killi_entitlement_manager()->packageIds();
$packagesConfig = killi_read_json(__DIR__ . '/../config/packages.json');
$currentPackageLabel = $currentPackage;
foreach ($packagesConfig['packages'] ?? [] as $pkg) {
    if ($pkg['id'] === $currentPackage) {
        $currentPackageLabel = $pkg['name'];
        break;
    }
}
$activeSource = killi_data_source_engine()->active();
?>
<?php
killi_admin_head('Setup');
killi_admin_body_open('setup');
?>
<style>
  .wrap { max-width: 560px; margin: 20px auto; }
  .steps { display: flex; gap: 8px; margin-bottom: 20px; }
  .steps span { flex: 1; height: 4px; border-radius: 2px; background: var(--admin-border); }
  .steps span.done, .steps span.active { background: var(--admin-primary); }
  .card { padding: 28px; }
  h1 { font-size: 20px; margin-top: 0; }
  button.secondary { background: none; color: var(--admin-muted); border: none; }
  .actions { display: flex; justify-content: space-between; align-items: center; }
  .summary-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--admin-border); font-size: 14px; }
  .summary-row:last-child { border-bottom: none; }
  .summary-row strong { color: var(--admin-success-ink); }
</style>
  <div class="steps">
    <span class="<?= $step >= 1 ? ($step > 1 ? 'done' : 'active') : '' ?>"></span>
    <span class="<?= $step >= 2 ? ($step > 2 ? 'done' : 'active') : '' ?>"></span>
    <span class="<?= $step >= 3 ? 'active' : '' ?>"></span>
  </div>

  <?php if ($notice): ?>
    <div class="notice <?= $notice['type'] ?>"><?= htmlspecialchars($notice['text']) ?></div>
  <?php endif; ?>

  <div class="card">
  <?php if ($step === 1): ?>
    <h1>Step 1 of 3 — License</h1>
    <p class="lead">You're on <strong>Free</strong> by default. If you have a Standard or Ultra license key, enter it now to unlock those features for this installation — or skip and add one later from <code>connections.php</code>.</p>
    <form method="post" action="?do=activate_license&amp;step=1">
      <?= killi_csrf_field() ?>
      <label>License key</label>
      <input name="license_key" placeholder="e.g. KILLI-ULTRA-DEMO-0001" autofocus>
      <div class="actions">
        <button type="submit">Activate &amp; continue</button>
        <button type="submit" name="do" value="skip_license" class="secondary" formnovalidate>Continue with Free &rarr;</button>
      </div>
    </form>

  <?php elseif ($step === 2): ?>
    <h1>Step 2 of 3 — App access</h1>
    <p class="lead">Optional. By default the customer-facing chat app is open to anyone with the link. Set a shared password here if you'd rather gate it — you can change this anytime from <code>connections.php</code>.</p>
    <form method="post" action="?do=set_app_password&amp;step=2">
      <?= killi_csrf_field() ?>
      <label>App password (optional)</label>
      <input type="password" name="app_password" placeholder="Leave blank to keep it open">
      <div class="actions">
        <button type="submit">Save &amp; continue</button>
        <button type="submit" name="do" value="skip_app_password" class="secondary" formnovalidate>Skip &rarr;</button>
      </div>
    </form>

  <?php else: ?>
    <h1>Step 3 of 3 — You're set up</h1>
    <p class="lead"><?= htmlspecialchars($productName) ?> is ready to use.</p>
    <div class="summary-row"><span>License / package</span><strong><?= htmlspecialchars($currentPackageLabel) ?></strong></div>
    <div class="summary-row"><span>App access</span><strong><?= killi_app_password_configured() ? 'Password required' : 'Open (no password)' ?></strong></div>
    <div class="summary-row"><span>Active data source</span><strong><?= htmlspecialchars($activeSource['name'] ?? 'Default demo dataset') ?></strong></div>
    <form method="post" action="?do=finish">
      <?= killi_csrf_field() ?>
      <button type="submit">Finish setup</button>
    </form>
    <p style="margin-top:20px">
      <a class="link" href="connections.php">Go to the admin panel</a> &nbsp;·&nbsp;
      <a class="link" href="../portal/index.php">Open the live app</a>
    </p>
  <?php endif; ?>
  </div>
<?php killi_admin_body_close(); ?>
