<?php

require_once __DIR__ . '/../bootstrap.php';

$branding = kili_branding();
$categories = kili_read_json(__DIR__ . '/../data/categories.json');
$colors = $branding['colors'] ?? [];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= htmlspecialchars($branding['product_name'] ?? 'KiliGoogle.ai') ?></title>
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
    <div class="kili-header-title"><?= htmlspecialchars($branding['product_name'] ?? 'KiliGoogle.ai') ?></div>
  </header>

  <div class="kili-chat" id="kili-chat"></div>

  <div class="kili-chips" id="kili-chips">
    <?php foreach ($categories as $cat): ?>
      <button class="kili-chip" data-category="<?= htmlspecialchars($cat['name']) ?>"><?= htmlspecialchars($cat['name']) ?></button>
    <?php endforeach; ?>
  </div>

  <form class="kili-searchbar" id="kili-searchbar" autocomplete="off">
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
</div>

<script>
window.KILI_BRANDING = <?= json_encode($branding) ?>;
</script>
<script src="../assets/js/kili.js"></script>
</body>
</html>
