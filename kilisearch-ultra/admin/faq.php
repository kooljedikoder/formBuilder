<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_chrome.php';

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

$logPath = __DIR__ . '/../data/query_log.json';
$faqConfig = killi_data_source_engine()->faqConfig();
$faqTied = ($faqConfig['mode'] ?? 'untied') === 'tied';

if (killi_has_feature('memory')) {
    $faqStorage = killi_faq_storage();

    if ($do === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $question = trim($_POST['question'] ?? '');
        $answer = trim($_POST['answer'] ?? '');
        if ($question === '' || $answer === '') {
            $notice = ['type' => 'error', 'text' => 'Question and answer are both required.'];
        } else {
            try {
                $tags = array_values(array_filter(array_map('trim', explode(',', $_POST['tags'] ?? ''))));
                $id = trim($_POST['id'] ?? '');
                $record = [
                    'id' => $id !== '' ? $id : null,
                    'question' => mb_strtolower($question),
                    'answer' => $answer,
                    'tags' => $tags,
                    'hit_count' => $id !== '' ? ($faqStorage->find($id)['hit_count'] ?? 0) : 0,
                    'linked_record_ids' => [],
                    'image' => null,
                ];
                $faqStorage->save($record);
                $notice = ['type' => 'success', 'text' => $id !== '' ? 'FAQ updated.' : 'FAQ added — it will now be matched against incoming questions.'];
            } catch (\RuntimeException $e) {
                $notice = ['type' => 'error', 'text' => $e->getMessage()];
            }
        }
    } elseif ($do === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $deleted = $faqStorage->delete($_POST['id'] ?? '');
            $notice = ['type' => 'success', 'text' => $deleted ? 'FAQ deleted.' : 'Unknown FAQ.'];
        } catch (\RuntimeException $e) {
            $notice = ['type' => 'error', 'text' => $e->getMessage()];
        }
    } elseif ($do === 'dismiss_query' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $normalized = $_POST['normalized'] ?? '';
        $log = killi_read_json($logPath);
        $remaining = array_values(array_filter($log, fn($e) => $e['normalized'] !== $normalized));
        file_put_contents($logPath, json_encode($remaining, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        $notice = ['type' => 'success', 'text' => 'Dismissed from the unanswered list.'];
    }
}

$faqs = killi_has_feature('memory') ? killi_faq_storage()->all() : [];
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
<?php
killi_admin_head('FAQ / Memory');
killi_admin_body_open('faq');
?>
  <h1>FAQ / Memory</h1>
  <p class="lead">What visitors have asked (logged automatically), and the curated answers the Memory engine recalls instantly next time.</p>

  <?php if ($notice): ?>
    <div class="notice <?= $notice['type'] ?>"><?= htmlspecialchars($notice['text']) ?></div>
  <?php endif; ?>

  <?php if (!killi_has_feature('memory')): ?>
    <div class="upsell">The Memory engine is a Standard/Ultra feature. Activate a license key on the <a class="link" href="connections.php">Connections</a> page to unlock it.</div>
  <?php else: ?>

  <p style="font-size:13px;color:var(--admin-muted)">
    FAQ source: <strong><?= $faqTied ? 'Tied' : 'Untied' ?></strong>
    <?= $faqTied
        ? ' — reading/writing the "' . htmlspecialchars($faqConfig['table'] ?? '') . '" table via the "' . htmlspecialchars($faqConfig['connection'] ?? '') . '" connection, the same one backing your active data source.'
        : ' — a dedicated local store, independent of whatever backs Search/CRUD.' ?>
    <a class="link" href="connections.php#faq-source">Change</a>
  </p>

  <div class="card">
    <h2 style="margin-top:0">Unanswered / frequently asked</h2>
    <p style="font-size:13px;color:var(--admin-muted)">Every question the Memory engine couldn't confidently match gets logged here — most-asked first. Promote the useful ones into a curated FAQ answer.</p>
    <?php if (!$log): ?>
      <p style="font-size:13px;color:var(--admin-muted)">Nothing logged yet.</p>
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
      <p style="font-size:13px;color:var(--admin-muted)">No FAQs yet.</p>
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
<?php killi_admin_body_close(); ?>
