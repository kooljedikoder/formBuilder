<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_chrome.php';

if (!killi_admin_password_configured() || !killi_is_admin_authenticated()) {
    header('Location: connections.php');
    exit;
}

$sessionPath = __DIR__ . '/../data/session_feedback.json';
$reactionPath = __DIR__ . '/../data/feedback.json';
$notice = null;
$do = $_REQUEST['do'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !killi_verify_csrf($_POST['csrf'] ?? '')) {
    $notice = ['type' => 'error', 'text' => 'Form expired — please reload and try again.'];
    $do = '';
}

if ($do === 'dismiss_session' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? '';
    $entries = killi_read_json($sessionPath);
    $remaining = array_values(array_filter($entries, fn($e) => $e['id'] !== $id));
    file_put_contents($sessionPath, json_encode($remaining, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    $notice = ['type' => 'success', 'text' => count($remaining) < count($entries) ? 'Rating dismissed.' : 'Unknown rating.'];
} elseif ($do === 'dismiss_reaction' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $index = (int) ($_POST['index'] ?? -1);
    $entries = killi_read_json($reactionPath);
    if (isset($entries[$index])) {
        array_splice($entries, $index, 1);
        file_put_contents($reactionPath, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        $notice = ['type' => 'success', 'text' => 'Reaction dismissed.'];
    } else {
        $notice = ['type' => 'error', 'text' => 'Unknown reaction.'];
    }
}

$sessionRatings = killi_read_json($sessionPath);
usort($sessionRatings, fn($a, $b) => ($a['rating'] ?? 5) <=> ($b['rating'] ?? 5));

$reactions = killi_read_json($reactionPath);
$reactions = array_reverse($reactions, true);
$negativeOnly = ($_GET['filter'] ?? '') === 'negative';
if ($negativeOnly) {
    $reactions = array_filter($reactions, fn($r) => in_array($r['emoji'] ?? '', ['😐', '👎'], true));
}
?>
<?php
killi_admin_head('Feedback');
killi_admin_body_open('feedback');
?>
<style>
  .rating-row { border-bottom: 1px solid var(--admin-border); padding: 10px 0; }
  .rating-row:last-child { border-bottom: none; }
  .stars { color: #f5b400; font-size: 14px; letter-spacing: 1px; }
  .rating-comment { font-size: 13px; color: var(--admin-text); margin: 4px 0; }
  .rating-meta { font-size: 12px; color: var(--admin-muted); }
  details.transcript { margin-top: 6px; }
  details.transcript summary { font-size: 12px; color: var(--admin-primary); cursor: pointer; }
  .turn { font-size: 12.5px; margin: 6px 0; padding-left: 10px; border-left: 2px solid var(--admin-border); }
  .turn .q { color: var(--admin-text); font-weight: 600; }
  .turn .a { color: var(--admin-muted); }
  button.danger { border-radius: 999px; padding: 4px 10px; font-size: 12px; }
  .reaction-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; border-bottom: 1px solid var(--admin-border); padding: 8px 0; font-size: 13px; }
  .reaction-row:last-child { border-bottom: none; }
  .reaction-emoji { font-size: 16px; }
  .filter-tabs a { font-size: 12.5px; margin-right: 12px; text-decoration: none; color: var(--admin-muted); }
  .filter-tabs a.active { color: var(--admin-primary); font-weight: 600; }
  .empty { font-size: 13px; color: var(--admin-muted); }
</style>
  <h1>Feedback</h1>
  <p class="lead">What visitors told you directly — end-of-conversation ratings and per-reply reactions — worst first, so the conversations worth reviewing surface on their own.</p>

  <?php if ($notice): ?>
    <div class="notice <?= $notice['type'] ?>"><?= htmlspecialchars($notice['text']) ?></div>
  <?php endif; ?>

  <div class="card">
    <h2 style="margin-top:0">Conversation ratings (<?= count($sessionRatings) ?>)</h2>
    <?php if (!$sessionRatings): ?>
      <p class="empty">Nothing rated yet — a "Rate this conversation" star icon appears in the chat header once a visitor has had at least one exchange.</p>
    <?php else: ?>
      <?php foreach ($sessionRatings as $rating): ?>
        <div class="rating-row">
          <div class="stars"><?= str_repeat('★', (int) $rating['rating']) . str_repeat('☆', 5 - (int) $rating['rating']) ?></div>
          <?php if (!empty($rating['comment'])): ?><p class="rating-comment">“<?= htmlspecialchars($rating['comment']) ?>”</p><?php endif; ?>
          <p class="rating-meta"><?= htmlspecialchars(substr($rating['created_at'] ?? '', 0, 16)) ?> · <?= count($rating['turns'] ?? []) ?> exchange(s)
            <form class="inline" method="post" action="?do=dismiss_session" onsubmit="return confirm('Dismiss this rating?')" style="margin-left:8px">
              <?= killi_csrf_field() ?>
              <input type="hidden" name="id" value="<?= htmlspecialchars($rating['id']) ?>">
              <button type="submit" class="danger">Dismiss</button>
            </form>
          </p>
          <?php if (!empty($rating['turns'])): ?>
          <details class="transcript">
            <summary>View transcript</summary>
            <?php foreach ($rating['turns'] as $turn): ?>
              <div class="turn"><div class="q"><?= htmlspecialchars($turn['query']) ?></div><div class="a"><?= htmlspecialchars($turn['reply']) ?></div></div>
            <?php endforeach; ?>
          </details>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2 style="margin-top:0">Reply reactions (<?= count($reactions) ?>)</h2>
    <p class="filter-tabs">
      <a href="feedback.php" class="<?= !$negativeOnly ? 'active' : '' ?>">All</a>
      <a href="?filter=negative" class="<?= $negativeOnly ? 'active' : '' ?>">😐 / 👎 only</a>
    </p>
    <?php if (!$reactions): ?>
      <p class="empty">No reactions <?= $negativeOnly ? 'matching that filter ' : '' ?>yet.</p>
    <?php else: ?>
      <?php foreach ($reactions as $index => $reaction): ?>
        <div class="reaction-row">
          <div><span class="reaction-emoji"><?= htmlspecialchars($reaction['emoji']) ?></span> &nbsp; “<?= htmlspecialchars(mb_strimwidth($reaction['reply'], 0, 100, '…')) ?>”</div>
          <form class="inline" method="post" action="?do=dismiss_reaction" onsubmit="return confirm('Dismiss this reaction?')">
            <?= killi_csrf_field() ?>
            <input type="hidden" name="index" value="<?= (int) $index ?>">
            <button type="submit" class="danger">Dismiss</button>
          </form>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
<?php killi_admin_body_close(); ?>
