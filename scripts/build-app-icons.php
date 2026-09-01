<?php
declare(strict_types=1);

if (!extension_loaded('gd')) {
    fwrite(STDERR, "GD extension is required.\n");
    exit(1);
}

$target = dirname(__DIR__) . '/public/assets/icons';
if (!is_dir($target)) mkdir($target, 0775, true);

foreach ([180 => 'apple-touch-icon.png', 192 => 'icon-192.png', 512 => 'icon-512.png', 513 => 'icon-maskable-512.png'] as $sizeKey => $name) {
    $size = $sizeKey === 513 ? 512 : $sizeKey;
    $image = imagecreatetruecolor($size, $size);
    imagesavealpha($image, true);
    $green = imagecolorallocate($image, 23, 107, 91);
    $cream = imagecolorallocate($image, 250, 247, 235);
    $gold = imagecolorallocate($image, 224, 181, 74);
    $p = static fn(float $ratio): int => (int)round($size * $ratio);
    imagefill($image, 0, 0, $green);
    $margin = $name === 'icon-maskable-512.png' ? (int)($size * .22) : (int)($size * .14);
    imagefilledellipse($image, $p(.5), $p(.5), $size - 2 * $margin, $size - 2 * $margin, $cream);
    $line = max(5, (int)($size * .045));
    imagesetthickness($image, $line);
    imagearc($image, $p(.5), $p(.5), $p(.47), $p(.47), 0, 360, $green);
    imageline($image, $p(.5), $p(.5), $p(.5), $p(.32), $green);
    imageline($image, $p(.5), $p(.5), $p(.66), $p(.56), $green);
    imagefilledellipse($image, $p(.72), $p(.72), $p(.19), $p(.19), $gold);
    imagesetthickness($image, max(4, (int)($size * .025)));
    imageline($image, $p(.67), $p(.72), $p(.71), $p(.77), $green);
    imageline($image, $p(.71), $p(.77), $p(.79), $p(.67), $green);
    imagepng($image, $target . '/' . $name);
    imagedestroy($image);
}

fwrite(STDOUT, "PWA icons generated.\n");
