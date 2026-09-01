<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!extension_loaded('gd')) {
    fwrite(STDERR, "GD extension is required.\n");
    exit(1);
}

const FRAME_SIZE = 16;
const FRAME_COUNT = 4;

$palette = [
    'D' => [17, 61, 69],    // deep teal outline
    'C' => [246, 229, 184], // cream fur
    'W' => [255, 248, 222], // muzzle/shirt
    'Y' => [232, 182, 63],  // necktie
    'P' => [232, 124, 104], // ears/nose
    'B' => [29, 97, 112],   // work bag
];

function pixel(array &$grid, int $x, int $y, string $color): void
{
    if ($x >= 0 && $x < FRAME_SIZE && $y >= 0 && $y < FRAME_SIZE) {
        $grid[$y][$x] = $color;
    }
}

function linePixels(array &$grid, array $points, string $color): void
{
    foreach ($points as [$x, $y]) pixel($grid, $x, $y, $color);
}

function horizontal(array &$grid, int $y, int $from, int $to, string $fill, string $outline = 'D'): void
{
    for ($x = $from; $x <= $to; $x++) pixel($grid, $x, $y, ($x === $from || $x === $to) ? $outline : $fill);
}

function drawFrame(int $pose): array
{
    $grid = array_fill(0, FRAME_SIZE, array_fill(0, FRAME_SIZE, '.'));

    // Tail swings up and down across the run cycle.
    $tails = [
        [[5,10],[4,10],[3,9],[2,8],[2,7],[3,6]],
        [[5,10],[4,9],[3,8],[3,7],[4,6],[4,5]],
        [[5,10],[4,10],[3,10],[2,9],[1,9],[1,8]],
        [[5,10],[4,9],[3,8],[2,7],[2,6],[3,5]],
    ];
    linePixels($grid, $tails[$pose], 'D');
    foreach (array_slice($tails[$pose], 1, -1) as [$x, $y]) pixel($grid, $x, $y, 'C');

    // Legs are deliberately exaggerated so the motion survives at 16px.
    $legs = [
        [[[6,11],[5,12],[4,13],[3,13]], [[9,11],[10,12],[11,13],[12,13]]],
        [[[6,11],[6,12],[7,13],[8,13]], [[9,11],[10,12],[10,13],[9,14]]],
        [[[6,11],[5,12],[5,13],[6,14]], [[9,11],[10,12],[11,13],[13,13]]],
        [[[6,11],[5,12],[4,13],[4,14]], [[9,11],[9,12],[8,13],[7,13]]],
    ];
    foreach ($legs[$pose] as $leg) {
        linePixels($grid, $leg, 'D');
        if (isset($leg[1])) pixel($grid, $leg[1][0], $leg[1][1], 'C');
    }

    // Torso.
    horizontal($grid, 7, 6, 11, 'C');
    horizontal($grid, 8, 5, 12, 'C');
    horizontal($grid, 9, 5, 12, 'C');
    horizontal($grid, 10, 5, 11, 'C');
    horizontal($grid, 11, 6, 10, 'C');

    // Head and ears.
    linePixels($grid, [[8,2],[9,1],[10,3],[12,2],[13,1],[13,3]], 'D');
    pixel($grid, 9, 2, 'P');
    pixel($grid, 12, 3, 'P');
    horizontal($grid, 3, 8, 13, 'C');
    horizontal($grid, 4, 7, 14, 'C');
    horizontal($grid, 5, 7, 14, 'C');
    horizontal($grid, 6, 7, 14, 'C');
    horizontal($grid, 7, 8, 13, 'C');

    // Face, shirt and tie.
    pixel($grid, 12, 4, 'D');
    pixel($grid, 14, 6, 'P');
    pixel($grid, 12, 6, 'W');
    pixel($grid, 13, 6, 'W');
    pixel($grid, 10, 7, 'W');
    pixel($grid, 10, 8, 'Y');
    pixel($grid, 10, 9, 'Y');

    // Running arms.
    $frontArms = [
        [[11,8],[12,8],[13,7],[14,7]],
        [[11,8],[12,9],[13,9]],
        [[11,8],[12,8],[13,8],[14,9]],
        [[11,8],[12,7],[13,7]],
    ];
    linePixels($grid, $frontArms[$pose], 'D');
    foreach (array_slice($frontArms[$pose], 1, -1) as [$x, $y]) pixel($grid, $x, $y, 'C');

    // Tiny work bag, readable as a dark block at this resolution.
    horizontal($grid, 8, 3, 6, 'B');
    horizontal($grid, 9, 2, 6, 'B');
    horizontal($grid, 10, 2, 6, 'B');
    horizontal($grid, 11, 3, 6, 'B');
    pixel($grid, 4, 9, 'Y');
    linePixels($grid, [[4,7],[5,7],[6,8]], 'D');

    return $grid;
}

$image = imagecreatetruecolor(FRAME_SIZE * FRAME_COUNT, FRAME_SIZE);
imagealphablending($image, false);
imagesavealpha($image, true);
$transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
imagefill($image, 0, 0, $transparent);

$colors = [];
foreach ($palette as $key => [$r, $g, $b]) {
    $colors[$key] = imagecolorallocatealpha($image, $r, $g, $b, 0);
}

for ($frame = 0; $frame < FRAME_COUNT; $frame++) {
    $grid = drawFrame($frame);
    foreach ($grid as $y => $row) {
        foreach ($row as $x => $key) {
            if ($key !== '.') imagesetpixel($image, $frame * FRAME_SIZE + $x, $y, $colors[$key]);
        }
    }
}

$output = dirname(__DIR__) . '/public/assets/cat-run-16x16.png';
if (!imagepng($image, $output, 9)) {
    fwrite(STDERR, "Could not write sprite sheet.\n");
    exit(1);
}
imagedestroy($image);
fwrite(STDOUT, "Created {$output} (64x16, 4 frames).\n");

