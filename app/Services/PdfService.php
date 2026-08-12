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
    public function make(array $options = []): Mpdf
    {
        $defaultConfig = (new ConfigVariables())->getDefaults();
        $fontDirs = $defaultConfig['fontDir'];

        $defaultFontConfig = (new FontVariables())->getDefaults();
        $fontData = $defaultFontConfig['fontdata'];

        $mpdf = new Mpdf(array_merge([
            'tempDir' => storage_path('app/mpdf-tmp'),
            'format' => 'A4',
            'margin_top' => 12,
            'margin_bottom' => 12,
            'margin_left' => 12,
            'margin_right' => 12,
            'mode' => 'utf-8',
            'fontDir' => array_merge($fontDirs, [storage_path('fonts')]),
            // useOTL enables OpenType shaping (GSUB/GPOS) — without it mPDF
            // draws Arabic letters in isolated form instead of joining them
            // into proper cursive script.
            'fontdata' => $fontData + [
                'tajawal' => ['R' => 'tajawal-regular.ttf', 'B' => 'tajawal-bold.ttf', 'useOTL' => 0xFF],
                'almarai' => ['R' => 'almarai-regular.ttf', 'B' => 'almarai-bold.ttf', 'useOTL' => 0xFF],
            ],
        ], $options));

        $brandFont = app(RestaurantSettings::class)->font_family;

        if (in_array($brandFont, ['tajawal', 'almarai'], true)) {
            // One font that fully covers Arabic + Latin — no need to
            // switch fonts per script, and doing so would fight the
            // explicit brand choice.
            $mpdf->SetDefaultFont($brandFont);
        } else {
            $mpdf->autoScriptToLang = true;
            $mpdf->autoLangToFont = true;
        }

        return $mpdf;
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
}
