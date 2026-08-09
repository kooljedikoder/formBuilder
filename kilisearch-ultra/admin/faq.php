<?php

require_once __DIR__ . '/../bootstrap.php';

if (!killi_admin_password_configured() || !killi_is_admin_authenticated()) {
    header('Location: connections.php');
    exit;
}

$notice = null;
$do = $_REQUEST['do'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !killi_verify_csrf($_POST['csrf'] ?? '')) {
    $notice = ['type' => 'error', 'text' => 'Form expired — please reload and try again.'];
    $do = '';
}

$faqPath = __DIR__ . '/../data/faq.json';
$logPath = __DIR__ . '/../data/query_log.json';

if (killi_has_feature('memory')) {
    if ($do === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $question = trim($_POST['question'] ?? '');
        $answer = trim($_POST['answer'] ?? '');
        if ($question === '' || $answer === '') {
            $notice = ['type' => 'error', 'text' => 'Question and answer are both required.'];
        } else {
            $faqs = killi_read_json($faqPath);
            $tags = array_values(array_filter(array_map('trim', explode(',', $_POST['tags'] ?? ''))));
            $id = trim($_POST['id'] ?? '');

            if ($id !== '') {
                foreach ($faqs as &$faq) {
                    if ($faq['id'] === $id) {
                        $faq['question'] = mb_strtolower($question);
                        $faq['answer'] = $answer;
                        $faq['tags'] = $tags;
                        break;
                    }
                }
                unset($faq);
                $notice = ['type' => 'success', 'text' => 'FAQ updated.'];
            } else {
                $maxNum = 0;
                foreach ($faqs as $faq) {
                    if (preg_match('/^faq-(\d+)$/', $faq['id'], $m)) {
                        $maxNum = max($maxNum, (int) $m[1]);
                    }
                }
                $faqs[] = [
                    'id' => 'faq-' . ($maxNum + 1),
                    'question' => mb_strtolower($question),
                    'answer' => $answer,
                    'tags' => $tags,
                    'hit_count' => 0,
                    'linked_record_ids' => [],
                    'image' => null,
                ];
                $notice = ['type' => 'success', 'text' => 'FAQ added — it will now be matched against incoming questions.'];
            }
            file_put_contents($faqPath, json_encode($faqs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        }
    } elseif ($do === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = $_POST['id'] ?? '';
        $faqs = killi_read_json($faqPath);
        $remaining = array_values(array_filter($faqs, fn($f) => $f['id'] !== $id));
        file_put_contents($faqPath, json_encode($remaining, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        $notice = ['type' => 'success', 'text' => count($remaining) < count($faqs) ? 'FAQ deleted.' : 'Unknown FAQ.'];
    } elseif ($do === 'dismiss_query' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $normalized = $_POST['normalized'] ?? '';
        $log = killi_read_json($logPath);
        $remaining = array_values(array_filter($log, fn($e) => $e['normalized'] !== $normalized));
        file_put_contents($logPath, json_encode($remaining, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        $notice = ['type' => 'success', 'text' => 'Dismissed from the unanswered list.'];
    }
}

$faqs = killi_read_json($faqPath);
$log = killi_read_json($logPath);
usort($log, fn($a, $b) => ($b['count'] ?? 0) <=> ($a['count'] ?? 0));

$editing = null;
if (isset($_GET['edit'])) {
    foreach ($faqs as $f) {
        if ($f['id'] === $_GET['edit']) {
            $editing = $f;
            break;
        }
    }
}
$prefillQuestion = $_GET['question'] ?? ($editing['question'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>KilliSearch Ultra — FAQ / Memory</title>
<style>
  body { font-family: -apple-system, Arial, sans-serif; background: #f5f6f8; color: #202124; margin: 0; padding: 24px; }
  .wrap { max-width: 780px; margin: 0 auto; }
  h1 { font-size: 20px; }
  h2 { font-size: 15px; }
  .card { background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; padding: 16px; margin-bottom: 16px; }
  .notice { padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
  .notice.success { background: #e6f4ea; color: #137333; }
  .notice.error { background: #fce8e6; color: #c5221f; }
  .upsell { background: #fef8e8; border: 1px solid #e0b23d; border-radius: 10px; padding: 16px; font-size: 14px; }
  label { display: block; font-size: 12px; color: #5f6368; margin-top: 10px; }
  input, textarea { width: 100%; padding: 8px; border: 1px solid #d0d0d0; border-radius: 6px; font-size: 14px; box-sizing: border-box; font-family: inherit; }
  button { padding: 8px 14px; border: none; border-radius: 6px; background: #1a73e8; color: #fff; font-size: 14px; cursor: pointer; margin-top: 12px; }
  button.secondary { background: #fff; color: #1a73e8; border: 1px solid #1a73e8; }
  button.danger { background: #fff; color: #c5221f; border: 1px solid #c5221f; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 8px; }
  table th, table td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
  a.link { color: #1a73e8; text-decoration: none; }
  form.inline { display: inline; }
  .nav a { font-size: 13px; color: #5f6368; margin-right: 14px; text-decoration: none; }
</style>
</head>
<body>
<div class="wrap">
  <div style="display:flex;justify-content:space-between;align-items:baseline">
    <h1>FAQ / Memory</h1>
    <span class="nav">
      <a href="connections.php">Connections</a>
      <a href="records.php">Records</a>
      <a href="backup.php">Backups</a>
    </span>
  </div>
  <p>What visitors have asked (logged automatically), and the curated answers the Memory engine recalls instantly next time.</p>

  <?php if ($notice): ?>
    <div class="notice <?= $notice['type'] ?>"><?= htmlspecialchars($notice['text']) ?></div>
  <?php endif; ?>

  <?php if (!killi_has_feature('memory')): ?>
    <div class="upsell">The Memory engine is a Standard/Ultra feature. Activate a license key on the <a class="link" href="connections.php">Connections</a> page to unlock it.</div>
  <?php else: ?>

  <div class="card">
    <h2 style="margin-top:0">Unanswered / frequently asked</h2>
    <p style="font-size:13px;color:#5f6368">Every question the Memory engine couldn't confidently match gets logged here — most-asked first. Promote the useful ones into a curated FAQ answer.</p>
    <?php if (!$log): ?>
      <p style="font-size:13px;color:#5f6368">Nothing logged yet.</p>
    <?php else: ?>
    <table>
      <tr><th>Question</th><th>Asked</th><th>Last asked</th><th></th></tr>
      <?php foreach (array_slice($log, 0, 30) as $entry): ?>
      <tr>
        <td><?= htmlspecialchars($entry['query']) ?></td>
        <td><?= (int) $entry['count'] ?></td>
        <td><?= htmlspecialchars(substr($entry['last_asked_at'] ?? '', 0, 10)) ?></td>
        <td>
          <a class="link" href="?question=<?= urlencode($entry['query']) ?>#faq-form">Save as FAQ</a>
          &nbsp;
          <form class="inline" method="post" action="?do=dismiss_query">
            <?= killi_csrf_field() ?>
            <input type="hidden" name="normalized" value="<?= htmlspecialchars($entry['normalized']) ?>">
            <button type="submit" class="secondary" style="padding:4px 8px;margin-top:0">Dismiss</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>

  <div class="card" id="faq-form">
    <h2 style="margin-top:0"><?= $editing ? 'Edit FAQ' : 'Add a FAQ' ?></h2>
    <form method="post" action="?do=save">
      <?= killi_csrf_field() ?>
      <input type="hidden" name="id" value="<?= htmlspecialchars($editing['id'] ?? '') ?>">
      <label>Question (what people ask)</label>
      <input name="question" value="<?= htmlspecialchars($prefillQuestion) ?>" required>
      <label>Answer</label>
      <textarea name="answer" rows="3" required><?= htmlspecialchars($editing['answer'] ?? '') ?></textarea>
      <label>Tags (comma-separated)</label>
      <input name="tags" value="<?= htmlspecialchars(implode(', ', $editing['tags'] ?? [])) ?>">
      <button type="submit"><?= $editing ? 'Save changes' : 'Add FAQ' ?></button>
      <?php if ($editing): ?> <a href="faq.php" style="font-size:13px;margin-left:8px">Cancel</a><?php endif; ?>
    </form>
  </div>

  <div class="card">
    <h2 style="margin-top:0">Curated FAQs (<?= count($faqs) ?>)</h2>
    <?php if (!$faqs): ?>
      <p style="font-size:13px;color:#5f6368">No FAQs yet.</p>
    <?php else: ?>
    <table>
      <tr><th>Question</th><th>Answer</th><th>Recalled</th><th></th></tr>
      <?php foreach ($faqs as $f): ?>
      <tr>
        <td><?= htmlspecialchars($f['question']) ?></td>
        <td><?= htmlspecialchars(mb_strimwidth($f['answer'], 0, 80, '…')) ?></td>
        <td><?= (int) ($f['hit_count'] ?? 0) ?></td>
        <td>
          <a class="link" href="?edit=<?= urlencode($f['id']) ?>#faq-form">Edit</a>
          &nbsp;
          <form class="inline" method="post" action="?do=delete" onsubmit="return confirm('Delete this FAQ?')">
            <?= killi_csrf_field() ?>
            <input type="hidden" name="id" value="<?= htmlspecialchars($f['id']) ?>">
            <button type="submit" class="danger" style="padding:4px 8px;margin-top:0">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>

  <?php endif; ?>
</div>
</body>
</html>
