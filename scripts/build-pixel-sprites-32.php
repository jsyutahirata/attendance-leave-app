<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!extension_loaded('gd')) { fwrite(STDERR, "GD extension is required.\n"); exit(1); }

const SIZE = 32;
const FRAMES = 4;

$image = imagecreatetruecolor(SIZE * FRAMES, SIZE);
imagealphablending($image, false);
imagesavealpha($image, true);
imageantialias($image, false);
$transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
imagefill($image, 0, 0, $transparent);
$color = [
    'outline' => imagecolorallocate($image, 17, 61, 69),
    'cream' => imagecolorallocate($image, 246, 229, 184),
    'light' => imagecolorallocate($image, 255, 248, 222),
    'yellow' => imagecolorallocate($image, 232, 182, 63),
    'coral' => imagecolorallocate($image, 232, 124, 104),
    'bag' => imagecolorallocate($image, 29, 97, 112),
    'shine' => imagecolorallocate($image, 117, 180, 174),
];

function poly($image, int $offset, array $points, int $color): void
{
    $shifted = [];
    foreach (array_chunk($points, 2) as [$x, $y]) { $shifted[] = $offset + $x; $shifted[] = $y; }
    imagefilledpolygon($image, $shifted, $color);
}
function ellipse($image, int $offset, int $cx, int $cy, int $width, int $height, int $color): void
{ imagefilledellipse($image, $offset + $cx, $cy, $width, $height, $color); }
function rect($image, int $offset, int $x1, int $y1, int $x2, int $y2, int $color): void
{ imagefilledrectangle($image, $offset + $x1, $y1, $offset + $x2, $y2, $color); }
function thickLine($image, int $offset, array $points, int $thickness, int $color): void
{
    imagesetthickness($image, $thickness);
    for ($i = 1; $i < count($points); $i++) imageline($image, $offset + $points[$i-1][0], $points[$i-1][1], $offset + $points[$i][0], $points[$i][1], $color);
    imagesetthickness($image, 1);
}

function drawCat($image, int $frame, array $c): void
{
    $o = $frame * SIZE;
    $bob = [0, -1, 0, -1][$frame];
    $tails = [
        [[10,22],[6,22],[3,19],[3,15],[5,12]],
        [[10,21],[7,19],[5,15],[7,11],[9,10]],
        [[10,22],[6,23],[2,22],[2,18],[4,16]],
        [[10,21],[7,19],[4,16],[4,12],[6,10]],
    ];
    thickLine($image, $o, $tails[$frame], 5, $c['outline']);
    thickLine($image, $o, $tails[$frame], 3, $c['cream']);

    $legs = [
        [[12,22,10,25,6,28,7,30,11,30,16,25], [18,22,21,24,24,27,29,27,29,29,25,30,18,27]],
        [[12,22,11,26,13,29,17,29,17,26,16,23], [18,22,22,23,25,26,23,29,20,28,17,25]],
        [[12,22,9,25,8,29,11,30,14,27,17,24], [18,22,21,25,25,28,29,28,29,30,25,31,18,27]],
        [[12,22,8,24,5,27,6,29,10,29,16,25], [18,22,19,26,17,29,20,30,23,27,22,23]],
    ];
    foreach ($legs[$frame] as $leg) {
        poly($image, $o, $leg, $c['outline']);
        $inner = $leg;
        for ($i=0; $i<count($inner); $i+=2) { $inner[$i] += $inner[$i] < 16 ? 1 : -1; $inner[$i+1] -= 1; }
        poly($image, $o, $inner, $c['cream']);
    }

    poly($image,$o,[3,16+$bob,11,15+$bob,15,25+$bob,4,26+$bob],$c['outline']);
    poly($image,$o,[4,17+$bob,10,16+$bob,13,24+$bob,5,24+$bob],$c['bag']);
    thickLine($image,$o,[[7,17+$bob],[9,13+$bob],[14,16+$bob]],2,$c['outline']);
    rect($image,$o,7,20+$bob,9,22+$bob,$c['yellow']);
    rect($image,$o,10,17+$bob,11,18+$bob,$c['shine']);

    ellipse($image,$o,16,19+$bob,17,17,$c['outline']);
    ellipse($image,$o,16,19+$bob,14,14,$c['cream']);
    poly($image,$o,[17,13+$bob,22,15+$bob,21,24+$bob,17,25+$bob],$c['light']);

    poly($image,$o,[13,8+$bob,14,2+$bob,19,6+$bob],$c['outline']);
    poly($image,$o,[14,7+$bob,15,4+$bob,18,7+$bob],$c['coral']);
    poly($image,$o,[21,6+$bob,26,2+$bob,27,10+$bob],$c['outline']);
    poly($image,$o,[23,6+$bob,25,4+$bob,25,9+$bob],$c['coral']);
    ellipse($image,$o,20,10+$bob,16,14,$c['outline']);
    ellipse($image,$o,20,10+$bob,14,12,$c['cream']);
    ellipse($image,$o,24,13+$bob,8,5,$c['light']);
    rect($image,$o,22,8+$bob,23,10+$bob,$c['outline']);
    rect($image,$o,22,8+$bob,22,8+$bob,$c['light']);
    rect($image,$o,27,12+$bob,28,13+$bob,$c['coral']);
    rect($image,$o,26,14+$bob,27,14+$bob,$c['outline']);
    rect($image,$o,13,10+$bob,15,10+$bob,$c['outline']);
    rect($image,$o,13,12+$bob,16,12+$bob,$c['outline']);

    poly($image,$o,[19,14+$bob,22,14+$bob,21,17+$bob,23,22+$bob,20,24+$bob,19,18+$bob],$c['outline']);
    poly($image,$o,[20,15+$bob,21,15+$bob,20,17+$bob,22,21+$bob,20,22+$bob,20,18+$bob],$c['yellow']);
    $arms = [
        [19,17+$bob,23,18+$bob,26,16+$bob,28,17+$bob,25,20+$bob,20,20+$bob],
        [19,17+$bob,23,19+$bob,27,20+$bob,27,22+$bob,23,22+$bob,19,20+$bob],
        [19,17+$bob,23,17+$bob,27,18+$bob,28,20+$bob,24,20+$bob,20,20+$bob],
        [19,17+$bob,23,16+$bob,26,14+$bob,28,15+$bob,25,18+$bob,20,20+$bob],
    ];
    poly($image,$o,$arms[$frame],$c['outline']);
    ellipse($image,$o,26,[17,21,19,15][$frame]+$bob,3,3,$c['cream']);
}

for ($frame=0; $frame<FRAMES; $frame++) drawCat($image,$frame,$color);
$output = dirname(__DIR__) . '/public/assets/cat-run-32x32.png';
if (!imagepng($image,$output,9)) { fwrite(STDERR,"Could not write sprite sheet.\n"); exit(1); }
imagedestroy($image);
fwrite(STDOUT,"Created {$output} (128x32, 4 frames).\n");
