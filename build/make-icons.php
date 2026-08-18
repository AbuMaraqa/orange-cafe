<?php

/**
 * Generates the desktop app's icon set into public/.
 *
 * NativePHP's build copies public/icon.png (Linux, and the tray icon this
 * app sets in NativeAppServiceProvider::showTray) and public/icon.ico
 * (Windows executable + installer) verbatim — there is no icon generator in
 * the package, so they have to exist before `php artisan native:build`.
 *
 * Run once, and again only if the mark changes:
 *   php build/make-icons.php
 *
 * Everything is drawn at 4x and downsampled, which is what gives the curves
 * and the diagonal cup edges their antialiasing — GD's own drawing
 * primitives are hard-aliased apart from imageantialias(), which does not
 * apply to filled polygons or to anything with alpha.
 */
$out = __DIR__.'/../public';

const SUPERSAMPLE = 2048;

const BG_TOP = [0xFF, 0x9A, 0x3E];      // warm orange, top-left
const BG_BOTTOM = [0xE8, 0x53, 0x0E];   // deep orange, bottom-right

$canvas = render(SUPERSAMPLE);

// 256 is what Windows shows at the largest shell size; 16 is the tray/title
// bar. Everything between covers the sizes File Explorer picks from.
$sizes = [256, 128, 96, 64, 48, 40, 32, 24, 20, 16];
$frames = [];

foreach ($sizes as $size) {
    $frames[$size] = downscale($canvas, $size);
}

// icon.png: the tray icon and the Linux app icon. 512 keeps it crisp on a
// HiDPI display, where the tray asks for the 2x variant.
$png512 = downscale($canvas, 512);
imagepng($png512, $out.'/icon.png');
echo "wrote public/icon.png (512x512)\n";

// favicon.ico in the repo is a 0-byte placeholder; browsers on the LAN
// tablets show a broken icon for it, so it gets the same mark.
writeIco($out.'/icon.ico', $frames);
echo "wrote public/icon.ico (".implode(', ', $sizes).")\n";

writeIco($out.'/favicon.ico', array_intersect_key($frames, array_flip([48, 32, 16])));
echo "wrote public/favicon.ico (48, 32, 16)\n";

/**
 * The mark: a rounded-square orange tile with a white coffee cup.
 */
function render(int $s)
{
    $img = imagecreatetruecolor($s, $s);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
    imagealphablending($img, true);

    drawRoundedGradient($img, $s);

    $white = imagecolorallocate($img, 0xFF, 0xFF, 0xFF);

    // Proportions are expressed as fractions of the canvas so the same code
    // renders any supersample size.
    $u = fn (float $f): int => (int) round($f * $s);

    // Cup body: a trapezoid narrowing towards the base, with a rounded
    // bottom formed by an ellipse tucked under the straight edges.
    imagefilledpolygon($img, [
        $u(0.250), $u(0.410),
        $u(0.640), $u(0.410),
        $u(0.585), $u(0.645),
        $u(0.305), $u(0.645),
    ], $white);
    imagefilledellipse($img, $u(0.445), $u(0.645), $u(0.280), $u(0.150), $white);

    // Handle, drawn after the body so its punched-out centre survives. Its
    // hole clears the cup's right edge (~0.62 at this height) entirely —
    // overlapping them would either fill the hole back in or bite a chunk
    // out of the cup.
    // Positioned so the ring's outer edge overlaps the cup wall (which sits
    // at ~0.618 at this height) while its hole stays clear of it — a handle
    // that merely floats alongside reads as a separate dot at 16px.
    drawRing($img, $s, $u(0.700), $u(0.505), $u(0.120), $u(0.060), $white);

    // Saucer.
    imagefilledellipse($img, $u(0.445), $u(0.750), $u(0.560), $u(0.078), $white);

    // Two steam ribbons above the cup.
    steam($img, $u(0.375), $u(0.150), $u(0.215), $u(0.044), $white);
    steam($img, $u(0.530), $u(0.195), $u(0.155), $u(0.040), $white);

    return $img;
}

function drawRoundedGradient($img, int $s): void
{
    $radius = 0.22 * $s;
    $inner = $s - $radius;

    for ($y = 0; $y < $s; $y++) {
        for ($x = 0; $x < $s; $x++) {
            if (! insideRoundedSquare($x, $y, $radius, $inner)) {
                continue;
            }

            imagesetpixel($img, $x, $y, gradientAt($img, $x, $y, $s));
        }
    }
}

function insideRoundedSquare(int $x, int $y, float $radius, float $inner): bool
{
    $cx = $x < $radius ? $radius : ($x > $inner ? $inner : $x);
    $cy = $y < $radius ? $radius : ($y > $inner ? $inner : $y);

    // Only the four corner regions need the distance test; everywhere else
    // the clamp lands on the pixel itself and the distance is zero.
    return (($x - $cx) ** 2 + ($y - $cy) ** 2) <= $radius ** 2;
}

/**
 * A ring: a filled white disc with the tile's own gradient painted back
 * into its centre.
 *
 * The obvious alternative — punching the centre out with a transparent
 * fill — is wrong here. It cuts a hole through the whole icon rather than
 * revealing the orange behind it, which is invisible while previewing on a
 * white page and shows up as a bite missing from the tile everywhere else.
 */
function drawRing($img, int $s, int $cx, int $cy, int $outer, int $inner, int $color): void
{
    imagefilledellipse($img, $cx, $cy, $outer * 2, $outer * 2, $color);

    for ($y = $cy - $inner; $y <= $cy + $inner; $y++) {
        for ($x = $cx - $inner; $x <= $cx + $inner; $x++) {
            if ((($x - $cx) ** 2 + ($y - $cy) ** 2) > $inner ** 2) {
                continue;
            }

            imagesetpixel($img, $x, $y, gradientAt($img, $x, $y, $s));
        }
    }
}

/**
 * The tile's background colour at one pixel — shared by the initial fill
 * and by anything that needs to paint background back over itself.
 */
function gradientAt($img, int $x, int $y, int $s): int
{
    // Diagonal ramp: 0 at the top-left corner, 1 at the bottom-right.
    $t = ($x + $y) / (2 * ($s - 1));

    return imagecolorallocate(
        $img,
        (int) round(BG_TOP[0] + ($t * (BG_BOTTOM[0] - BG_TOP[0]))),
        (int) round(BG_TOP[1] + ($t * (BG_BOTTOM[1] - BG_TOP[1]))),
        (int) round(BG_TOP[2] + ($t * (BG_BOTTOM[2] - BG_TOP[2]))),
    );
}

/**
 * One steam ribbon: an S-curve traced as a run of overlapping discs, which
 * gives round caps and a smooth join for free.
 *
 * One and a half sine periods, not two — two packs both reversals into a
 * short run and the stroke reads as a hard zigzag rather than rising steam.
 */
function steam($img, int $x, int $top, int $height, int $thickness, int $color): void
{
    $steps = 200;

    for ($i = 0; $i <= $steps; $i++) {
        $t = $i / $steps;
        $y = $top + (int) round($t * $height);
        $offset = (int) round(sin($t * M_PI * 1.5) * ($thickness * 0.85));

        imagefilledellipse($img, $x + $offset, $y, $thickness, $thickness, $color);
    }
}

function downscale($src, int $size)
{
    $dst = imagecreatetruecolor($size, $size);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));

    imagecopyresampled($dst, $src, 0, 0, 0, 0, $size, $size, imagesx($src), imagesy($src));

    return $dst;
}

/**
 * Writes a PNG-compressed .ico. Every Windows version this app targets
 * reads PNG-in-ICO entries, and it keeps the 256px frame from bloating the
 * file to the ~256KB an uncompressed BMP entry would cost.
 *
 * @param  array<int, \GdImage>  $frames  keyed by pixel size
 */
function writeIco(string $path, array $frames): void
{
    $encoded = [];

    foreach ($frames as $size => $frame) {
        ob_start();
        imagepng($frame);
        $encoded[$size] = ob_get_clean();
    }

    // ICONDIR: reserved, type 1 (icon), image count.
    $header = pack('vvv', 0, 1, count($encoded));

    // Each ICONDIRENTRY is 16 bytes and follows the 6-byte header, so the
    // first image's data starts after all of them.
    $offset = 6 + (16 * count($encoded));
    $directory = '';
    $body = '';

    foreach ($encoded as $size => $bytes) {
        $directory .= pack(
            'CCCCvvVV',
            $size >= 256 ? 0 : $size,  // 0 means 256 in this field
            $size >= 256 ? 0 : $size,
            0,                          // palette size (0 = truecolor)
            0,                          // reserved
            1,                          // colour planes
            32,                         // bits per pixel
            strlen($bytes),
            $offset,
        );

        $body .= $bytes;
        $offset += strlen($bytes);
    }

    file_put_contents($path, $header.$directory.$body);
}
