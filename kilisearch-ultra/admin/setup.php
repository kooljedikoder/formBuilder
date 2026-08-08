<?php

require_once __DIR__ . '/../bootstrap.php';

// This wizard is part of the admin surface, so it needs the same admin
// gate as connections.php — but it must never become a second setup path.
// If no admin password exists yet, send the visitor to connections.php,
// which owns creating that password; it will bounce back here afterwards.
if (!kili_admin_password_configured() || !kili_is_admin_authenticated()) {
    header('Location: connections.php');
    exit;
}

// The very first run (fresh install) is always by the owner just created in
// admin_setup. Re-running the wizard afterward touches licensing/app-access —
// owner-only — so an editor has no legitimate reason to be here.
if (kili_setup_complete() && !kili_is_admin_owner()) {
    header('Location: connections.php');
    exit;
}

$step = (int) ($_GET['step'] ?? 1);
if ($step < 1 || $step > 3) {
    $step = 1;
}
$notice = null;
$do = $_REQUEST['do'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !kili_verify_csrf($_POST['csrf'] ?? '')) {
    $notice = ['type' => 'error', 'text' => 'Form expired — please reload and try again.'];
    $do = '';
}

if ($do === 'activate_license' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $key = trim($_POST['license_key'] ?? '');
    if ($key === '') {
        header('Location: setup.php?step=2');
        exit;
    }
    $result = kili_activate_license($key);
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
        kili_save_env_value('KILI_APP_PASSWORD_HASH', password_hash($password, PASSWORD_DEFAULT));
    }
    header('Location: setup.php?step=3');
    exit;
} elseif ($do === 'skip_app_password') {
    header('Location: setup.php?step=3');
    exit;
} elseif ($do === 'finish') {
    kili_mark_setup_complete();
    header('Location: connections.php');
    exit;
}

$branding = kili_branding();
$productName = $branding['product_name'] ?? 'KilliGoogle.ai';
$currentPackage = kili_current_package();
$packageName = kili_entitlement_manager()->packageIds();
$packagesConfig = kili_read_json(__DIR__ . '/../config/packages.json');
$currentPackageLabel = $currentPackage;
foreach ($packagesConfig['packages'] ?? [] as $pkg) {
    if ($pkg['id'] === $currentPackage) {
        $currentPackageLabel = $pkg['name'];
        break;
    }
}
$activeSource = kili_data_source_engine()->active();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Set up <?= htmlspecialchars($productName) ?></title>
<style>
  body { font-family: -apple-system, Arial, sans-serif; background: #f5f6f8; color: #202124; margin: 0; padding: 24px; }
  .wrap { max-width: 560px; margin: 40px auto; }
  .steps { display: flex; gap: 8px; margin-bottom: 20px; }
  .steps span { flex: 1; height: 4px; border-radius: 2px; background: #e0e0e0; }
  .steps span.done, .steps span.active { background: #1a73e8; }
  .card { background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; padding: 28px; }
  h1 { font-size: 20px; margin-top: 0; }
  p.lead { color: #5f6368; font-size: 14px; }
  .notice { padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
  .notice.error { background: #fce8e6; color: #c5221f; }
  .notice.success { background: #e6f4ea; color: #137333; }
  label { display: block; font-size: 12px; color: #5f6368; margin-top: 10px; }
  input { width: 100%; padding: 10px; border: 1px solid #d0d0d0; border-radius: 6px; font-size: 14px; box-sizing: border-box; margin-top: 4px; }
  button { margin-top: 16px; padding: 10px 16px; border: none; border-radius: 6px; background: #1a73e8; color: #fff; font-size: 14px; cursor: pointer; }
  button.secondary { background: none; color: #5f6368; }
  .actions { display: flex; justify-content: space-between; align-items: center; }
  .summary-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f0f0f0; font-size: 14px; }
  .summary-row:last-child { border-bottom: none; }
  .summary-row strong { color: #137333; }
  a.link { color: #1a73e8; text-decoration: none; font-size: 13px; }
</style>
</head>
<body>
<div class="wrap">
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
      <?= kili_csrf_field() ?>
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
      <?= kili_csrf_field() ?>
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
    <div class="summary-row"><span>App access</span><strong><?= kili_app_password_configured() ? 'Password required' : 'Open (no password)' ?></strong></div>
    <div class="summary-row"><span>Active data source</span><strong><?= htmlspecialchars($activeSource['name'] ?? 'Default demo dataset') ?></strong></div>
    <form method="post" action="?do=finish">
      <?= kili_csrf_field() ?>
      <button type="submit">Finish setup</button>
    </form>
    <p style="margin-top:20px">
      <a class="link" href="connections.php">Go to the admin panel</a> &nbsp;·&nbsp;
      <a class="link" href="../portal/index.php">Open the live app</a>
    </p>
  <?php endif; ?>
  </div>
</div>
</body>
</html>
