<?php

namespace App\Services;

use App\Settings\RestaurantSettings;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;

/**
 * Thin factory around Mpdf so every PDF in the app (invoices, table QR
 * signs) shares the same temp directory, fonts, and defaults instead of
 * each controller configuring Mpdf from scratch.
 */
class PdfService
{
    /**
     * The brand font chosen in Settings (Tajawal/Almarai) is downloaded
     * once into storage/fonts and registered here, so PDFs match the same
     * typeface used on the customer menu — not mPDF's generic bundled
     * fonts. "system" has no single font that covers every script, so it
     * keeps mPDF's automatic per-script font switching instead.
     */
    /**
     * What every PDF falls back to when the interface font has no TTF.
     *
     * Tajawal, because it is shipped with the app, covers Arabic and Latin
     * in one face, and is already what most of this cafe's paper is set in.
     */
    private const PRINT_FALLBACK_FONT = 'tajawal';

    public function make(array $options = []): Mpdf
    {
        // mPDF needs its scratch directory to already exist — it writes
        // there for every document and throws if it cannot, but it never
        // creates it. Locally the folder happens to be lying around from
        // development and is gitignored, so nothing ever noticed; in the
        // packaged app NativePHP relocates storage to the user's data
        // directory and creates only logs/, framework/cache/ and
        // framework/sessions/. Every invoice and every thermal ticket then
        // failed with a bare 500 — printing and PDFs both dead on a fresh
        // install, with nothing on screen to say why.
        $this->ensureDirectory(storage_path('app/mpdf-tmp'));
        $this->ensureDirectory(storage_path('fonts'));

        $defaultConfig = (new ConfigVariables)->getDefaults();
        $fontDirs = $defaultConfig['fontDir'];

        $defaultFontConfig = (new FontVariables)->getDefaults();
        $fontData = $defaultFontConfig['fontdata'];

        $mpdf = new Mpdf(array_merge([
            'tempDir' => storage_path('app/mpdf-tmp'),
            'format' => 'A4',
            'margin_top' => 12,
            'margin_bottom' => 12,
            'margin_left' => 12,
            'margin_right' => 12,
            'mode' => 'utf-8',
            // Both the relocated storage and the shipped copy.
            //
            // The brand fonts are committed to storage/fonts and packaged
            // with the app, but nothing ever installs them into the
            // *relocated* storage: NativePHP moves storage/ to the user's
            // data directory and creates only logs/, framework/cache/ and
            // framework/sessions/ there. So on a fresh install the fonts
            // shipped inside the app and mPDF looked for them somewhere
            // else, throwing "Cannot find TTF TrueType font file" — which
            // meant every kitchen ticket and every invoice died before
            // reaching the printer, while the plain-text test page printed
            // perfectly and made the printer look fine.
            //
            // base_path() is the app's own directory and is never
            // relocated, so the shipped copy is always findable. In
            // development the two paths are the same folder.
            'fontDir' => array_merge($fontDirs, array_unique([
                storage_path('fonts'),
                base_path('storage/fonts'),
            ])),
            // useOTL enables OpenType shaping (GSUB/GPOS) — without it mPDF
            // draws Arabic letters in isolated form instead of joining them
            // into proper cursive script.
            'fontdata' => $fontData + [
                'tajawal' => ['R' => 'tajawal-regular.ttf', 'B' => 'tajawal-bold.ttf', 'useOTL' => 0xFF],
                'almarai' => ['R' => 'almarai-regular.ttf', 'B' => 'almarai-bold.ttf', 'useOTL' => 0xFF],
            ],
        ], $options));

        $brandFont = app(RestaurantSettings::class)->font_family;

        // Only if the file is actually somewhere mPDF will look.
        //
        // Asking for a font whose file is missing does not degrade the
        // document, it throws while rendering it — so a font that failed to
        // ship took the whole ticket with it. A ticket in a substitute face
        // is worth immeasurably more than no ticket, and the kitchen does
        // not care which typeface the order arrived in.
        if (in_array($brandFont, ['tajawal', 'almarai'], true) && $this->fontIsInstalled($brandFont)) {
            // One font that fully covers Arabic + Latin — no need to
            // switch fonts per script, and doing so would fight the
            // explicit brand choice.
            $mpdf->SetDefaultFont($brandFont);
        } elseif ($this->fontIsInstalled(self::PRINT_FALLBACK_FONT)) {
            // Any other choice — 'system', or a face like IBM Plex Sans
            // Arabic that ships as woff2 for the browser and has no TTF
            // here — falls back to Tajawal rather than to mPDF's automatic
            // per-script switching.
            //
            // Automatic switching is worse than it sounds on an Arabic
            // document: it picks a bundled face per run of text, so a table
            // of Arabic labels and Latin timestamps comes out in two
            // different typefaces on the same line. Tajawal covers both
            // scripts, is already shipped for the invoices, and looks like
            // the rest of the restaurant's paper.
            $mpdf->SetDefaultFont(self::PRINT_FALLBACK_FONT);
        } else {
            $mpdf->autoScriptToLang = true;
            $mpdf->autoLangToFont = true;
        }

        return $mpdf;
    }

    /**
     * Whether a brand font's regular face is actually on disk.
     *
     * mPDF resolves fonts lazily, at render time, so it cannot be asked
     * this — by the time it answers, the document has already failed.
     *
     * @param  'tajawal'|'almarai'  $font
     */
    public function fontIsInstalled(string $font): bool
    {
        foreach ([storage_path('fonts'), base_path('storage/fonts')] as $directory) {
            if (is_file($directory.DIRECTORY_SEPARATOR.$font.'-regular.ttf')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  'ltr'|'rtl'  $direction
     */
    public function fromView(string $view, array $data = [], string $direction = 'ltr', array $options = []): Mpdf
    {
        $mpdf = $this->make($options);
        $mpdf->SetDirectionality($direction);
        $mpdf->WriteHTML(view($view, $data)->render());

        return $mpdf;
    }

    /**
     * Creates a directory mPDF depends on, quietly doing nothing when it is
     * already there. Failure is deliberately not fatal here: a PDF that
     * cannot be written should surface as mPDF's own error, which names the
     * path, rather than as a mkdir warning from somewhere further up.
     */
    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            @mkdir($path, 0755, true);
        }
    }
}
