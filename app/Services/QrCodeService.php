<?php

namespace App\Services;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * Renders QR codes with plain GD instead of going through bacon-qr-code's
 * built-in renderers, which only rasterize via the Imagick extension (not
 * installed here) — GD is bundled with PHP everywhere. Drawing the module
 * matrix by hand also makes it trivial to composite a logo in the center
 * afterwards using the same GD image.
 */
class QrCodeService
{
    /**
     * High error correction ('H') tolerates roughly 30% obstruction, which
     * comfortably covers a centered logo at the default 26% size.
     */
    public function generatePng(string $data, ?string $logoAbsolutePath = null, int $size = 500, float $logoPercentage = 0.26): string
    {
        $qrCode = Encoder::encode($data, ErrorCorrectionLevel::H());
        $matrix = $qrCode->getMatrix();
        $moduleCount = $matrix->getWidth();

        $quietZone = 2;
        $scale = max(1, intdiv($size, $moduleCount + $quietZone * 2));
        $imageSize = $scale * ($moduleCount + $quietZone * 2);

        $image = imagecreatetruecolor($imageSize, $imageSize);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefill($image, 0, 0, $white);

        for ($y = 0; $y < $moduleCount; $y++) {
            for ($x = 0; $x < $moduleCount; $x++) {
                if ($matrix->get($x, $y) === 1) {
                    $px = ($x + $quietZone) * $scale;
                    $py = ($y + $quietZone) * $scale;
                    imagefilledrectangle($image, $px, $py, $px + $scale - 1, $py + $scale - 1, $black);
                }
            }
        }

        if ($logoAbsolutePath && is_readable($logoAbsolutePath)) {
            $this->overlayLogo($image, $logoAbsolutePath, $imageSize, $logoPercentage);
        }

        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        return $png;
    }

    private function overlayLogo($image, string $logoPath, int $imageSize, float $logoPercentage): void
    {
        $logo = imagecreatefromstring(file_get_contents($logoPath));

        if ($logo === false) {
            return;
        }

        $logoWidth = imagesx($logo);
        $logoHeight = imagesy($logo);

        $boxSize = (int) round($imageSize * $logoPercentage);
        $padding = (int) round($boxSize * 0.12);
        $boxX = (int) (($imageSize - $boxSize) / 2);
        $boxY = (int) (($imageSize - $boxSize) / 2);
        $radius = (int) round($boxSize * 0.18);

        $border = imagecolorallocate($image, 210, 210, 210);
        $white = imagecolorallocate($image, 255, 255, 255);
        $this->drawRoundedRect($image, $boxX - 3, $boxY - 3, $boxSize + 6, $boxSize + 6, $radius, $border);
        $this->drawRoundedRect($image, $boxX, $boxY, $boxSize, $boxSize, $radius, $white);

        $innerSize = $boxSize - $padding * 2;
        $ratio = min($innerSize / $logoWidth, $innerSize / $logoHeight);
        $destWidth = (int) round($logoWidth * $ratio);
        $destHeight = (int) round($logoHeight * $ratio);
        $destX = $boxX + (int) (($boxSize - $destWidth) / 2);
        $destY = $boxY + (int) (($boxSize - $destHeight) / 2);

        imagecopyresampled($image, $logo, $destX, $destY, 0, 0, $destWidth, $destHeight, $logoWidth, $logoHeight);
        imagedestroy($logo);
    }

    private function drawRoundedRect($image, int $x, int $y, int $w, int $h, int $radius, int $color): void
    {
        $radius = min($radius, (int) ($w / 2), (int) ($h / 2));

        imagefilledrectangle($image, $x + $radius, $y, $x + $w - $radius, $y + $h, $color);
        imagefilledrectangle($image, $x, $y + $radius, $x + $w, $y + $h - $radius, $color);
        imagefilledellipse($image, $x + $radius, $y + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x + $w - $radius, $y + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x + $radius, $y + $h - $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x + $w - $radius, $y + $h - $radius, $radius * 2, $radius * 2, $color);
    }
}
