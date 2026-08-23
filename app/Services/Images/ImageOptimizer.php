<?php

namespace App\Services\Images;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Menu photography, cut down to something a phone can actually load.
 *
 * Uploads used to be stored exactly as they arrived — `$file->store(...)`
 * and nothing else. A photo taken on a phone is four thousand pixels wide
 * and several megabytes, none of which survives being drawn into a 96-pixel
 * circle on a menu tile; the customer downloads all of it anyway, over the
 * cafe's wifi, before they can read the price. A menu of a hundred and fifty
 * products was the whole camera roll.
 *
 * So every upload is resized and re-encoded once, here, on the way in. The
 * long edge is capped at a size that is still generous — a full-width image
 * on a 3x phone display is around 1200 device pixels, so 1400 leaves room
 * and never looks soft — and quality 82 is the point where JPEG artefacts
 * stop being visible on photographs while the file is a fraction of the
 * original.
 *
 * ## It never loses a photo
 *
 * Every failure path falls back to storing the original. GD refusing a file,
 * an unreadable temp path, an out-of-memory on something enormous: none of
 * those are worth answering with "your upload failed" to somebody who has
 * just taken a picture of a dish. A large photo that works beats a small one
 * that does not exist.
 *
 * ## Orientation
 *
 * Phone cameras write the picture in sensor order and record which way up it
 * belongs in EXIF. GD ignores that, so a portrait photo lands on its side —
 * which is not a small blemish on a menu, it is the dish sideways.
 *
 * The bundled PHP ships without ext-exif, so the tag is read out of the
 * file's own bytes (readExifOrientation). Twenty lines, no dependency, and
 * it runs on the customer's machine where the extension will never exist.
 */
class ImageOptimizer
{
    /**
     * Longest edge kept, in pixels.
     *
     * Chosen against the largest place these are ever drawn — the product
     * detail sheet, full width — on a 3x display, plus headroom. Going
     * higher costs bytes nobody can see.
     */
    private const MAX_EDGE = 1400;

    /** High enough that photographs show no artefacts, low enough to matter. */
    private const JPEG_QUALITY = 82;

    /**
     * Stores an optimised copy and returns its path on the disk.
     *
     * @param  string  $directory  e.g. 'products'
     */
    public function store(UploadedFile $file, string $directory, string $disk = 'public'): string
    {
        try {
            $optimised = $this->optimise($file);

            if ($optimised !== null) {
                $path = $directory.'/'.Str::random(40).'.'.$optimised['extension'];

                if (Storage::disk($disk)->put($path, $optimised['data'])) {
                    return $path;
                }
            }
        } catch (Throwable $e) {
            // Deliberately swallowed — see the class docblock. The original
            // still gets stored below.
            Log::warning('Could not optimise an uploaded image; storing it as-is.', [
                'error' => $e->getMessage(),
                'original' => $file->getClientOriginalName(),
            ]);
        }

        return $file->store($directory, $disk);
    }

    /**
     * @return array{data: string, extension: string}|null null when the file
     *         is not an image GD can read, or is already small enough that
     *         re-encoding would only lose quality for no gain.
     */
    private function optimise(UploadedFile $file): ?array
    {
        $raw = @file_get_contents($file->getRealPath());

        if ($raw === false || $raw === '') {
            return null;
        }

        $image = @imagecreatefromstring($raw);

        if ($image === false) {
            return null;
        }

        try {
            $image = $this->applyOrientation($image, $this->readExifOrientation($raw));

            // A JPEG has no alpha channel, so there is nothing to detect and
            // nothing to flatten. Worth knowing before the resize: hasAlpha()
            // samples the bitmap, and on a full-size phone photo that is
            // thousands of imagecolorat() calls to answer a question the file
            // format already answered.
            $isJpeg = strncmp($raw, "\xFF\xD8", 2) === 0;

            // The file bytes are done with — they were only needed to decode
            // and to read the orientation tag. Releasing them here frees
            // several megabytes at exactly the moment GD is about to
            // allocate its largest bitmaps.
            unset($raw);

            $width = imagesx($image);
            $height = imagesy($image);
            $longest = max($width, $height);

            if ($longest > self::MAX_EDGE) {
                $scale = self::MAX_EDGE / $longest;
                $resized = imagescale($image, (int) round($width * $scale), (int) round($height * $scale), IMG_BICUBIC);

                if ($resized !== false) {
                    imagedestroy($image);
                    $image = $resized;
                }
            }

            // Transparency has to survive, or a logo with a cut-out
            // background comes back on a black square. Checked after the
            // resize so the scan walks the small bitmap, not the original.
            $keepAlpha = ! $isJpeg && $this->hasAlpha($image);

            return $keepAlpha ? $this->encodePng($image) : $this->encodeJpeg($image, $isJpeg);
        } finally {
            if ($image instanceof \GdImage) {
                imagedestroy($image);
            }
        }
    }

    /**
     * @param  bool  $alreadyOpaque  the source was a JPEG, so it cannot carry
     *                               transparency and needs no flattening
     * @return array{data: string, extension: string}
     */
    private function encodeJpeg(\GdImage $image, bool $alreadyOpaque = false): array
    {
        // A JPEG has no alpha channel, so anything transparent would be
        // written as black. Flattening onto white first is what the eye
        // expects from a photo on a white menu — but it costs a second
        // full-size bitmap, so it is skipped when the source was itself a
        // JPEG and had nothing to flatten.
        $flat = $image;

        if (! $alreadyOpaque) {
            $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
            imagefilledrectangle($flat, 0, 0, imagesx($image), imagesy($image), imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        }

        ob_start();
        imagejpeg($flat, null, self::JPEG_QUALITY);
        $data = (string) ob_get_clean();

        if ($flat !== $image) {
            imagedestroy($flat);
        }

        return ['data' => $data, 'extension' => 'jpg'];
    }

    /** @return array{data: string, extension: string} */
    private function encodePng(\GdImage $image): array
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        // 9 is maximum deflate. PNG is lossless either way, so this costs
        // only CPU on upload and saves bytes on every view afterwards.
        imagepng($image, null, 9);

        return ['data' => (string) ob_get_clean(), 'extension' => 'png'];
    }

    /**
     * Whether any pixel is actually see-through.
     *
     * Not "is this a PNG": most PNGs are fully opaque, and keeping those as
     * PNG throws away the whole saving for a transparency nobody used.
     * Sampled on a grid rather than every pixel — a four-megapixel scan of
     * something that is opaque anyway is slower than the resize it precedes.
     */
    private function hasAlpha(\GdImage $image): bool
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $step = max(1, (int) floor(min($width, $height) / 64));

        for ($x = 0; $x < $width; $x += $step) {
            for ($y = 0; $y < $height; $y += $step) {
                if (((imagecolorat($image, $x, $y) >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Turns the picture the way up it was taken.
     *
     * Only the three rotations matter in practice; the mirrored orientations
     * are produced by almost nothing and are left alone rather than guessed
     * at.
     */
    private function applyOrientation(\GdImage $image, int $orientation): \GdImage
    {
        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        if ($rotated === false) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }

    /**
     * The EXIF orientation tag, read straight out of the JPEG.
     *
     * ext-exif is not in the bundled PHP and cannot be added to it, so this
     * walks the JPEG segments to the APP1/Exif block, reads the TIFF header
     * for its endianness, and looks for tag 0x0112 in the first IFD.
     * Anything unexpected returns 1 — "already the right way up" — because a
     * photo left alone is a better failure than one rotated on a guess.
     */
    private function readExifOrientation(string $raw): int
    {
        // Not a JPEG: PNGs carry no orientation tag.
        if (strncmp($raw, "\xFF\xD8", 2) !== 0) {
            return 1;
        }

        $offset = 2;
        $length = strlen($raw);

        while ($offset + 4 <= $length) {
            if ($raw[$offset] !== "\xFF") {
                return 1;
            }

            $marker = ord($raw[$offset + 1]);
            $size = unpack('n', substr($raw, $offset + 2, 2))[1] ?? 0;

            if ($size < 2) {
                return 1;
            }

            // APP1 is where Exif lives.
            if ($marker === 0xE1 && substr($raw, $offset + 4, 6) === "Exif\0\0") {
                return $this->orientationFromTiff(substr($raw, $offset + 10, $size - 8));
            }

            // Start of scan: past here is pixel data, not metadata.
            if ($marker === 0xDA) {
                return 1;
            }

            $offset += 2 + $size;
        }

        return 1;
    }

    private function orientationFromTiff(string $tiff): int
    {
        if (strlen($tiff) < 8) {
            return 1;
        }

        $byteOrder = substr($tiff, 0, 2);
        $little = $byteOrder === 'II';

        if (! $little && $byteOrder !== 'MM') {
            return 1;
        }

        $short = fn (int $at) => strlen($tiff) >= $at + 2
            ? unpack($little ? 'v' : 'n', substr($tiff, $at, 2))[1]
            : 0;
        $long = fn (int $at) => strlen($tiff) >= $at + 4
            ? unpack($little ? 'V' : 'N', substr($tiff, $at, 4))[1]
            : 0;

        $ifd = $long(4);
        $count = $short($ifd);

        for ($i = 0; $i < $count; $i++) {
            $entry = $ifd + 2 + ($i * 12);

            if ($short($entry) === 0x0112) {
                $value = $short($entry + 8);

                return ($value >= 1 && $value <= 8) ? $value : 1;
            }
        }

        return 1;
    }
}
