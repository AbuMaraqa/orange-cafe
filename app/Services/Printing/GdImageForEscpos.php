<?php

namespace App\Services\Printing;

use Mike42\Escpos\EscposImage;
use SplFixedArray;

/**
 * mike42/escpos-php v2.2's GdEscposImage rejects every image on PHP 8+:
 * it checks is_resource() on the GD handle, but imagecreatefrompng()
 * has returned a GdImage object (not a resource) since PHP 8.0, so the
 * check is always false and it throws "Failed to load image." even
 * though GD loaded the file correctly. This reimplements just the
 * loading step against a GdImage object and hands off to the (unaffected)
 * base class for header/raster-format generation, which is what actually
 * matters for correct ESC/POS output — the earlier corrupted print
 * turned out to be a partial-socket-write bug, not bad pixel packing.
 */
class GdImageForEscpos extends EscposImage
{
    protected function loadImageData($filename = null)
    {
        if ($filename === null) {
            parent::loadImageData($filename);

            return;
        }

        $im = imagecreatefrompng($filename);
        $width = imagesx($im);
        $height = imagesy($im);

        $gray = new SplFixedArray($width * $height);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($im, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $gray[$y * $width + $x] = 0.299 * $r + 0.587 * $g + 0.114 * $b;
            }
        }

        imagedestroy($im);

        $this->darkenMidtones($gray);

        $this->setImgWidth($width);
        $this->setImgHeight($height);
        $this->setImgData($this->ditherToBlackAndWhite($gray, $width, $height));
    }

    /**
     * A 1-bit thermal printer physically can't reproduce light/pastel
     * tones — dithering represents them "correctly" as a very sparse dot
     * pattern, which reads as almost invisible on paper (this is exactly
     * what happened to a pastel-colored logo). Gamma correction with
     * gamma > 1 disproportionately darkens midtones while leaving true
     * black and true white anchored, so already-black receipt text is
     * unaffected but light logo/line-art content becomes dense enough to
     * actually read as ink on paper.
     */
    private function darkenMidtones(SplFixedArray $gray): void
    {
        $gamma = 2.4;
        $count = $gray->getSize();

        for ($i = 0; $i < $count; $i++) {
            $gray[$i] = 255 * (($gray[$i] / 255) ** $gamma);
        }
    }

    // Pixels this dark/light are anchored to solid black/white with no
    // error diffused — protects small, thin glyphs (a lone digit like
    // "1", a "×") whose anti-aliased edge pixels are almost their whole
    // footprint. Left to dithering, those edge pixels scatter into a
    // broken/gappy shape instead of a clean small glyph; the range in
    // between (where a logo's pastel colors live) still dithers normally.
    private const ANCHOR_BLACK_BELOW = 90;

    private const ANCHOR_WHITE_ABOVE = 200;

    /**
     * Floyd–Steinberg error-diffusion dithering. A flat black/white
     * cutoff makes any light or gradient content (a logo's pastel line
     * art, for instance) vanish entirely on a 1-bit thermal printer —
     * dithering spreads each pixel's quantization error onto its
     * not-yet-processed neighbors, producing a stippled pattern that
     * reads as the right tone from normal viewing distance.
     */
    private function ditherToBlackAndWhite(SplFixedArray $gray, int $width, int $height): string
    {
        $data = str_repeat("\0", $width * $height);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $i = $y * $width + $x;
                $old = $gray[$i];

                if ($old <= self::ANCHOR_BLACK_BELOW) {
                    $data[$i] = '1';

                    continue;
                }

                if ($old >= self::ANCHOR_WHITE_ABOVE) {
                    $data[$i] = '0';

                    continue;
                }

                $new = $old < 128 ? 0 : 255;
                $data[$i] = $new === 0 ? '1' : '0';
                $error = $old - $new;

                if ($x + 1 < $width) {
                    $gray[$i + 1] += $error * 7 / 16;
                }

                if ($y + 1 < $height) {
                    if ($x > 0) {
                        $gray[$i + $width - 1] += $error * 3 / 16;
                    }
                    $gray[$i + $width] += $error * 5 / 16;

                    if ($x + 1 < $width) {
                        $gray[$i + $width + 1] += $error * 1 / 16;
                    }
                }
            }
        }

        return $data;
    }
}
