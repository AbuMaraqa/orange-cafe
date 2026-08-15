<?php

namespace App\Services\Printing;

use App\Services\PdfService;
use Mike42\Escpos\PrintConnectors\DummyPrintConnector;
use Mike42\Escpos\Printer as EscposPrinter;
use Mpdf\Output\Destination;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Renders a Blade view to a ready-to-send ESC/POS ticket (init + image +
 * feed + cut), as a bitmap rather than raw text. Cheap thermal printers'
 * built-in fonts generally can't shape Arabic script (join letters into
 * their initial/medial/final forms) — text sent as plain codepage bytes
 * prints as disconnected, out-of-order glyphs. mPDF already renders
 * Arabic correctly (OpenType shaping via PdfService), so the view is
 * rendered through that same pipeline, rasterized to a bitmap via
 * poppler's pdftoppm, and packed into the ESC/POS raster command via
 * mike42/escpos-php — shared by both the kitchen ticket and the cashier
 * receipt, since both need exactly this same treatment.
 */
class EscPosImageRenderer
{
    // 72mm (576 dots at 203 DPI) is the documented *printable* width for
    // the vast majority of "80mm" ESC/POS thermal printers — the full
    // 80mm paper width includes margin the print head can't reach.
    // Content wider than that risks silently wrapping/corrupting rows.
    private const PAGE_WIDTH_MM = 72;

    private const DPI = 203;

    public function __construct(private PdfService $pdf)
    {
    }

    public function render(string $view, array $data, string $direction, int $heightMm): string
    {
        $pngPath = $this->renderImage($view, $data, $direction, $heightMm);

        try {
            $image = new GdImageForEscpos($pngPath, false);

            $connector = new DummyPrintConnector;
            $printer = new EscposPrinter($connector);
            $printer->initialize();
            $printer->setJustification(EscposPrinter::JUSTIFY_CENTER);
            $printer->bitImage($image);
            $printer->feed(3);
            $printer->cut();

            $bytes = $connector->getData();
            $printer->close();

            return $bytes;
        } finally {
            @unlink($pngPath);
        }
    }

    /**
     * Renders the view through mPDF, then rasterizes it to a PNG via
     * poppler's pdftoppm. Returns the PNG path — caller is responsible
     * for deleting it.
     */
    protected function renderImage(string $view, array $data, string $direction, int $heightMm): string
    {
        $mpdf = $this->pdf->fromView($view, $data, $direction, [
            'format' => [self::PAGE_WIDTH_MM, $heightMm],
            'margin_top' => 3, 'margin_bottom' => 3, 'margin_left' => 3, 'margin_right' => 3,
        ]);

        $dir = storage_path('app/thermal-print-tmp');

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $id = uniqid('ticket_', true);
        $pdfPath = "{$dir}/{$id}.pdf";
        $pngPath = "{$dir}/{$id}.png";

        $mpdf->Output($pdfPath, Destination::FILE);

        try {
            // -singlefile writes exactly "{prefix}.png" instead of
            // "{prefix}-1.png", so the output path is known up front.
            $process = new Process([
                config('services.poppler.pdftoppm_path'), '-png', '-r', (string) self::DPI, '-singlefile', $pdfPath, "{$dir}/{$id}",
            ]);
            $process->setTimeout(10);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            return $pngPath;
        } finally {
            @unlink($pdfPath);
        }
    }
}
