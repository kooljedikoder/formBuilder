<?php

/**
 * Reference example, not a real feature — illustrates the integration
 * contract from KILLI_BUILD_STATUS.md's "plugged into a main app" section.
 *
 * A real host application (a CRM, a portal, whatever already has its own
 * users and licensing) authenticates its own user through its OWN login
 * system — nothing here — and then, in its own server-side code, sets:
 *
 *     $_SESSION['killi_host_user'] = ['user_id' => $user->id, 'package' => $user->plan];
 *
 * before sending the visitor into Killi (an iframe, a redirect, a shared
 * layout — whatever the integration looks like). Killi trusts that value
 * via killi_current_package() in bootstrap.php instead of running its own
 * login for this purpose. This page fakes the "host app already logged
 * someone in" part so the handoff can be demonstrated and tested without
 * a real host application to plug into yet.
 */

require_once __DIR__ . '/../bootstrap.php';

killi_ensure_session();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['package'])) {
    $package = $_POST['package'];
    if (in_array($package, killi_entitlement_manager()->packageIds(), true)) {
        $_SESSION['killi_host_user'] = ['user_id' => 'demo-' . $package, 'package' => $package];
    }
} elseif (isset($_GET['logout'])) {
    unset($_SESSION['killi_host_user']);
}

$current = $_SESSION['killi_host_user'] ?? null;
$packages = killi_entitlement_manager()->packageIds();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Example Host Application</title>
<style>
  body { font-family: -apple-system, Arial, sans-serif; background: #f5f6f8; color: #202124; margin: 0; padding: 24px; }
  .wrap { max-width: 480px; margin: 40px auto; background: #fff; border-radius: 12px; padding: 24px; }
  h1 { font-size: 18px; }
  p { font-size: 13px; color: #5f6368; }
  .status { padding: 10px 14px; border-radius: 8px; margin: 16px 0; font-size: 14px; background: #e6f4ea; color: #137333; }
  .status.none { background: #f1f3f4; color: #5f6368; }
  select, button, a.button { width: 100%; padding: 10px; border-radius: 8px; font-size: 14px; box-sizing: border-box; text-align: center; text-decoration: none; display: block; }
  select { border: 1px solid #d0d0d0; margin-bottom: 10px; }
  button { border: none; background: #1a73e8; color: #fff; cursor: pointer; }
  a.button { background: #0f9d58; color: #fff; margin-top: 12px; }
  a.logout { display: inline-block; margin-top: 10px; font-size: 13px; color: #5f6368; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Example Host Application</h1>
  <p>Stands in for a real CRM/portal that already has its own users and licensing. "Logging in" here sets the same session value a real integration would set server-side, then hands off to KilliSearch.</p>

  <div class="status <?= $current ? '' : 'none' ?>">
    <?= $current ? 'Signed in as ' . htmlspecialchars($current['user_id']) . ' — package: <strong>' . htmlspecialchars($current['package']) . '</strong>' : 'Not signed in to the host app (KilliSearch will use its configured default package)' ?>
  </div>

  <form method="post">
    <select name="package">
      <?php foreach ($packages as $id): ?>
        <option value="<?= htmlspecialchars($id) ?>" <?= ($current['package'] ?? '') === $id ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($id)) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit">Log in as this user</button>
  </form>

  <a class="button" href="../portal/index.php" target="_blank">Continue to KilliSearch →</a>
  <?php if ($current): ?><a class="logout" href="?logout=1">Log out of host app</a><?php endif; ?>
</div>
</body>
</html>
