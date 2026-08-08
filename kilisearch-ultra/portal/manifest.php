<?php

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/manifest+json');

$branding = kili_branding();
$colors = $branding['colors'] ?? [];
$name = $branding['product_name'] ?? 'Killi';

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
], JSON_UNESCAPED_SLASHES);
