<?php

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/manifest+json');

$branding = kili_branding();
$colors = $branding['colors'] ?? [];
$name = $branding['product_name'] ?? 'Killi';

// Long-press the installed icon → jump straight into a sector, same as
// tapping its chip on the main screen (fills the search input, no
// auto-submit — see the ?sector= handling in assets/js/kili.js).
$sectors = array_slice(array_column(kili_taxonomy_engine()->tree(), 'sector'), 0, 4);
$shortcuts = array_map(fn($sector) => [
    'name' => $sector,
    'url' => 'index.php?sector=' . rawurlencode($sector),
], $sectors);

echo json_encode([
    'name' => $name,
    'short_name' => mb_substr($name, 0, 12),
    'description' => $branding['tagline'] ?? 'Search and chat',
    'start_url' => 'index.php',
    'scope' => './',
    'display' => 'standalone',
    'background_color' => $colors['background'] ?? '#ffffff',
    'theme_color' => $colors['chat_header'] ?? '#1a73e8',
    'icons' => [
        ['src' => 'icon.php?size=192', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ['src' => 'icon.php?size=512', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
    ],
    'shortcuts' => $shortcuts,
], JSON_UNESCAPED_SLASHES);
