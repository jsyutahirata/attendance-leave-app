<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!extension_loaded('gd')) { fwrite(STDERR, "GD extension is required.\n"); exit(1); }

const HERO_SIZE = 32;
const HERO_FRAMES = 4;

$image = imagecreatetruecolor(HERO_SIZE * HERO_FRAMES, HERO_SIZE);
imagealphablending($image, false);
imagesavealpha($image, true);
imageantialias($image, false);
$transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
imagefill($image, 0, 0, $transparent);
$c = [
    'outline' => imagecolorallocate($image, 26, 35, 58),
    'skin' => imagecolorallocate($image, 244, 196, 139),
    'hair' => imagecolorallocate($image, 91, 55, 42),
    'blue' => imagecolorallocate($image, 50, 105, 145),
    'blueLight' => imagecolorallocate($image, 85, 153, 176),
    'gold' => imagecolorallocate($image, 235, 180, 65),
    'red' => imagecolorallocate($image, 171, 66, 67),
    'brown' => imagecolorallocate($image, 107, 68, 45),
    'silver' => imagecolorallocate($image, 203, 215, 210),
    'white' => imagecolorallocate($image, 255, 242, 211),
];

function hPoly($image, int $offset, array $points, int $color): void
{
    $shifted=[];
    foreach(array_chunk($points,2) as [$x,$y]){$shifted[]=$offset+$x;$shifted[]=$y;}
    imagefilledpolygon($image,$shifted,$color);
}
function hRect($image,int $offset,int $x1,int $y1,int $x2,int $y2,int $color):void
{ imagefilledrectangle($image,$offset+$x1,$y1,$offset+$x2,$y2,$color); }
function hEllipse($image,int $offset,int $x,int $y,int $w,int $h,int $color):void
{ imagefilledellipse($image,$offset+$x,$y,$w,$h,$color); }
function hLine($image,int $offset,array $points,int $thickness,int $color):void
{
    imagesetthickness($image,$thickness);
    for($i=1;$i<count($points);$i++)imageline($image,$offset+$points[$i-1][0],$points[$i-1][1],$offset+$points[$i][0],$points[$i][1],$color);
    imagesetthickness($image,1);
}

function drawHero($image,int $frame,array $c):void
{
    $o=$frame*HERO_SIZE;
    $bob=[0,-1,0,-1][$frame];

    // Cape motion establishes the silhouette before the body is drawn.
    $capes=[
        [13,12+$bob,8,14+$bob,5,21+$bob,10,23+$bob,15,19+$bob],
        [13,12+$bob,7,12+$bob,4,18+$bob,9,22+$bob,15,19+$bob],
        [13,12+$bob,8,15+$bob,4,23+$bob,11,24+$bob,15,19+$bob],
        [13,12+$bob,6,11+$bob,3,16+$bob,9,21+$bob,15,19+$bob],
    ];
    hPoly($image,$o,$capes[$frame],$c['outline']);
    $inner=$capes[$frame];for($i=0;$i<count($inner);$i+=2){$inner[$i]+=1;$inner[$i+1]+=($i===1?1:0);}hPoly($image,$o,$inner,$c['red']);

    // Sword on the back.
    hLine($image,$o,[[11,17+$bob],[7,9+$bob]],3,$c['outline']);
    hLine($image,$o,[[11,17+$bob],[7,9+$bob]],1,$c['silver']);
    hLine($image,$o,[[6,11+$bob],[9,8+$bob]],2,$c['gold']);

    $legs=[
        [[13,22,10,25,6,28,7,30,11,30,16,25],[18,22,21,24,24,27,29,27,29,30,24,30,18,27]],
        [[13,22,11,26,13,29,17,29,17,25],[18,22,22,23,25,26,23,30,20,29,17,25]],
        [[13,22,9,25,8,29,11,30,15,26],[18,22,21,25,25,28,29,28,29,30,25,31,18,27]],
        [[13,22,8,24,5,27,6,30,10,29,16,25],[18,22,19,26,17,29,20,30,23,27,22,23]],
    ];
    foreach($legs[$frame] as $i=>$leg){hPoly($image,$o,$leg,$c['outline']);$boot=array_slice($leg,-6);hPoly($image,$o,$boot,$c['brown']);}

    // Tunic and belt.
    hPoly($image,$o,[12,13+$bob,20,13+$bob,23,23+$bob,10,23+$bob],$c['outline']);
    hPoly($image,$o,[13,14+$bob,19,14+$bob,21,21+$bob,12,21+$bob],$c['blue']);
    hRect($image,$o,11,19+$bob,22,21+$bob,$c['outline']);
    hRect($image,$o,13,19+$bob,20,19+$bob,$c['gold']);
    hRect($image,$o,16,19+$bob,17,20+$bob,$c['white']);
    hRect($image,$o,12,15+$bob,13,18+$bob,$c['blueLight']);

    // Head, hair and small heroic headband.
    hEllipse($image,$o,19,9+$bob,13,13,$c['outline']);
    hEllipse($image,$o,20,10+$bob,11,11,$c['skin']);
    hPoly($image,$o,[14,8+$bob,15,4+$bob,20,2+$bob,25,5+$bob,26,8+$bob,22,6+$bob,19,7+$bob],$c['hair']);
    hRect($image,$o,15,6+$bob,25,7+$bob,$c['blue']);
    hRect($image,$o,23,6+$bob,25,7+$bob,$c['gold']);
    hRect($image,$o,23,9+$bob,24,10+$bob,$c['outline']);
    hRect($image,$o,24,9+$bob,24,9+$bob,$c['white']);
    hRect($image,$o,26,12+$bob,27,13+$bob,$c['skin']);
    hRect($image,$o,25,14+$bob,26,14+$bob,$c['outline']);

    // Scarf and animated front arm.
    hPoly($image,$o,[14,12+$bob,22,12+$bob,21,15+$bob,15,15+$bob],$c['outline']);
    hRect($image,$o,16,13+$bob,20,14+$bob,$c['red']);
    $arms=[
        [19,15+$bob,23,16+$bob,27,14+$bob,29,16+$bob,25,19+$bob,20,18+$bob],
        [19,15+$bob,23,18+$bob,28,19+$bob,28,21+$bob,23,21+$bob,19,18+$bob],
        [19,15+$bob,23,15+$bob,28,17+$bob,29,19+$bob,24,19+$bob,20,18+$bob],
        [19,15+$bob,23,14+$bob,27,12+$bob,29,14+$bob,25,17+$bob,20,18+$bob],
    ];
    hPoly($image,$o,$arms[$frame],$c['outline']);
    hEllipse($image,$o,27,[15,20,18,13][$frame]+$bob,3,3,$c['skin']);
}

for($frame=0;$frame<HERO_FRAMES;$frame++)drawHero($image,$frame,$c);
$directory=dirname(__DIR__).'/public/assets/sprites/hero';
if(!is_dir($directory)&&!mkdir($directory,0775,true)&&!is_dir($directory)){fwrite(STDERR,"Could not create sprite directory.\n");exit(1);}
$output=$directory.'/run.png';
if(!imagepng($image,$output,9)){fwrite(STDERR,"Could not write hero sprite.\n");exit(1);}
imagedestroy($image);
fwrite(STDOUT,"Created {$output} (128x32, 4 frames).\n");

