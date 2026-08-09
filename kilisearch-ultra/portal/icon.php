<?php

require_once __DIR__ . '/../bootstrap.php';

$size = (int) ($_GET['size'] ?? 192);
$size = in_array($size, [192, 512], true) ? $size : 192;

$branding = killi_branding();
$bg = $branding['colors']['primary'] ?? '#1a73e8';
[$r, $g, $b] = sscanf($bg, '#%02x%02x%02x') ?: [26, 115, 232];

// No TTF font is bundled with the app (a font file is real weight/licensing
// baggage for a two-letter icon, and there's no guarantee an arbitrary
// deployment server has one installed) — so the mark is drawn as plain
// GD shapes instead of rendered text. Sized well inside a safe zone so a
// circular/rounded "maskable" crop (Android's install icon) never clips it.
$image = imagecreatetruecolor($size, $size);
$bgColor = imagecolorallocate($image, $r, $g, $b);
imagefill($image, 0, 0, $bgColor);

$white = imagecolorallocate($image, 255, 255, 255);
$cx = (int) ($size / 2);
$cy = (int) ($size / 2);
imagefilledellipse($image, $cx, $cy, (int) ($size * 0.52), (int) ($size * 0.52), $white);
imagefilledellipse($image, $cx, $cy, (int) ($size * 0.40), (int) ($size * 0.40), $bgColor);
imagefilledellipse($image, $cx, $cy, (int) ($size * 0.16), (int) ($size * 0.16), $white);

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
imagepng($image);
imagedestroy($image);
