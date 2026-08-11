<?php

namespace App\Services;

use Mpdf\Mpdf;

/**
 * Thin factory around Mpdf so every PDF in the app (invoices, table QR
 * signs) shares the same temp directory and sane defaults instead of each
 * controller configuring Mpdf from scratch. autoScriptToLang/autoLangToFont
 * let mPDF pick an Arabic-capable bundled font automatically when the
 * content is Arabic, without us hardcoding one font for every language.
 */
class PdfService
{
    public function make(array $options = []): Mpdf
    {
        $mpdf = new Mpdf(array_merge([
            'tempDir' => storage_path('app/mpdf-tmp'),
            'format' => 'A4',
            'margin_top' => 12,
            'margin_bottom' => 12,
            'margin_left' => 12,
            'margin_right' => 12,
            'mode' => 'utf-8',
        ], $options));

        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont = true;

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
