<?php

namespace App\Services\Printing;

use App\Services\PdfService;
use Mike42\Escpos\PrintConnectors\DummyPrintConnector;
use Mike42\Escpos\Printer as EscposPrinter;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use RuntimeException;
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
 *
 * Thermal paper is a continuous roll with no page height of its own, but
 * mPDF has to be given one, and pdftoppm rasterizes a page — so whatever
 * height is chosen becomes the length of the ticket. It is measured here
 * (see measureContentHeight) rather than estimated by the caller, because
 * an estimate is wrong in both directions and one of them loses food: too
 * short and mPDF starts a second page that `-singlefile` never rasterizes,
 * so every item on it is silently missing from the ticket the kitchen
 * receives; too tall and every ticket trails blank paper.
 */
class EscPosImageRenderer
{
    // 72mm (576 dots at 203 DPI) is the documented *printable* width for
    // the vast majority of "80mm" ESC/POS thermal printers — the full
    // 80mm paper width includes margin the print head can't reach.
    // Content wider than that risks silently wrapping/corrupting rows.
    private const PAGE_WIDTH_MM = 72;

    private const MARGIN_MM = 3;

    private const DPI = 203;

    /**
     * The page the measuring pass is laid out on: tall enough that no real
     * ticket reaches the bottom of it, so the content's height can be read
     * off in one go. Nothing is rasterized from this pass, so its size
     * costs no paper.
     */
    private const MEASURE_HEIGHT_MM = 2000;

    /** Absorbs float rounding between the measuring and the final pass. */
    private const HEADROOM_MM = 2;

    public function __construct(
        private PdfService $pdf,
        private PopplerBinary $poppler,
    ) {}

    public function render(string $view, array $data, string $direction): string
    {
        $pngPath = $this->renderImage($view, $data, $direction);
        $bands = [];

        try {
            $connector = new DummyPrintConnector;
            $printer = new EscposPrinter($connector);
            $printer->initialize();
            $printer->setJustification(EscposPrinter::JUSTIFY_CENTER);

            // One raster command per band, not one for the whole ticket.
            //
            // A thermal printer's receive buffer is small — commonly 4KB to
            // 64KB — and a ticket sent as a single `GS v 0` overruns it: the
            // printer answers with a buffer-error beep and prints nothing at
            // all. A ten-line ticket is already ~79KB, so this was not an
            // edge case, it was most of a busy evening.
            //
            // Banding fixes it without changing what comes out: the printer
            // prints each band as it arrives and the buffer never holds more
            // than one, so the ticket's length stops mattering.
            foreach ($this->bandPaths($pngPath) as $bandPath) {
                $bands[] = $bandPath;
                $printer->bitImage(new GdImageForEscpos($bandPath, false));
            }

            $printer->feed(3);
            $printer->cut();

            $bytes = $connector->getData();
            $printer->close();

            return $bytes;
        } finally {
            @unlink($pngPath);

            foreach ($bands as $bandPath) {
                @unlink($bandPath);
            }
        }
    }

    /**
     * How many pixel rows go in one raster command.
     *
     * 576 dots wide packs to 72 bytes a row, so a band is about 9KB — small
     * enough for the least generous buffer on the market, large enough that
     * a normal ticket is a handful of commands rather than hundreds.
     */
    private const BAND_HEIGHT_PX = 128;

    /**
     * Slices the ticket into horizontal bands, top to bottom.
     *
     * Written out as files because GdImageForEscpos loads from a path. They
     * are temporary and the caller deletes them.
     *
     * @return array<int, string> band file paths, in print order
     */
    private function bandPaths(string $pngPath): array
    {
        $source = imagecreatefrompng($pngPath);
        $width = imagesx($source);
        $height = imagesy($source);
        $paths = [];

        try {
            for ($top = 0; $top < $height; $top += self::BAND_HEIGHT_PX) {
                $bandHeight = min(self::BAND_HEIGHT_PX, $height - $top);
                $band = imagecreatetruecolor($width, $bandHeight);

                // White, not the default black: an unwritten pixel has to
                // read as blank paper, and a black band would print as a
                // solid stripe and empty the ribbonless printer's heat
                // budget for nothing.
                imagefill($band, 0, 0, imagecolorallocate($band, 255, 255, 255));
                imagecopy($band, $source, 0, 0, 0, $top, $width, $bandHeight);

                $path = $pngPath.'.band'.count($paths).'.png';
                imagepng($band, $path);
                imagedestroy($band);

                $paths[] = $path;
            }
        } finally {
            imagedestroy($source);
        }

        return $paths;
    }

    /**
     * Renders the view through mPDF, then rasterizes it to a PNG via
     * poppler's pdftoppm. Returns the PNG path — caller is responsible
     * for deleting it.
     */
    protected function renderImage(string $view, array $data, string $direction): string
    {
        $pdftoppm = $this->poppler->path();

        // Fail here with a sentence that names the actual problem, rather
        // than letting Symfony's Process throw "The command ... failed"
        // with an empty stderr further down. On the packaged app the binary
        // is bundled by build/fetch-poppler.ps1; the control panel's
        // "الطباعة الحرارية" row reports the same condition up front.
        if ($pdftoppm === null) {
            throw new RuntimeException(
                'pdftoppm (poppler) was not found, so thermal tickets cannot be rasterized. '
                .'Expected a bundled copy at '.base_path('bin/poppler').', poppler-utils on PATH, '
                .'or an explicit POPPLER_PDFTOPPM_PATH.'
            );
        }

        $mpdf = $this->layOut($view, $data, $direction);

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
            // "{prefix}-1.png", so the output path is known up front. It
            // also converts only the first page, which is why layOut()
            // guarantees there is only ever one.
            $process = new Process([
                $pdftoppm, '-png', '-r', (string) self::DPI, '-singlefile', $pdfPath, "{$dir}/{$id}",
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

    /**
     * The ticket, laid out on a page exactly as long as its own content.
     *
     * Two mPDF passes rather than one: the first only measures, the second
     * is the one that gets rasterized. Paying for a second layout is worth
     * it because the alternative — the caller estimating a height from the
     * item count — cannot account for a dish name that wraps, a modifier
     * line, an item note, or the order note, and underestimating any of
     * them pushes the tail of the ticket onto a page that never prints.
     */
    private function layOut(string $view, array $data, string $direction): Mpdf
    {
        $height = (int) ceil($this->measureContentHeight($view, $data, $direction))
            + self::MARGIN_MM * 2
            + self::HEADROOM_MM;

        $mpdf = $this->pdf->fromView($view, $data, $direction, $this->pageOptions($height));

        if ($this->pageCount($mpdf) === 1) {
            return $mpdf;
        }

        // Belt and braces. The measurement comes from the same content at
        // the same width, so this is not expected to happen — but a ticket
        // missing its last dish is the one outcome this class exists to
        // prevent, so it is never allowed to be the quiet path.
        $mpdf = $this->pdf->fromView($view, $data, $direction, $this->pageOptions($height * 2));

        if ($this->pageCount($mpdf) > 1) {
            throw new RuntimeException(
                "Ticket content did not fit on a single {$height}mm page even at double the measured "
                .'height; refusing to print a ticket that would be missing items.'
            );
        }

        return $mpdf;
    }

    /**
     * How many millimetres of ticket the view actually produces.
     *
     * `y` is where mPDF left the cursor, measured from the top of the page
     * it is currently on — so a ticket long enough to paginate even on the
     * measuring page has to add back the pages before it.
     */
    private function measureContentHeight(string $view, array $data, string $direction): float
    {
        $mpdf = $this->pdf->fromView($view, $data, $direction, $this->pageOptions(self::MEASURE_HEIGHT_MM));

        $body = self::MEASURE_HEIGHT_MM - self::MARGIN_MM * 2;

        return ($this->pageCount($mpdf) - 1) * $body + ($mpdf->y - self::MARGIN_MM);
    }

    private function pageCount(Mpdf $mpdf): int
    {
        return max(1, count($mpdf->pages));
    }

    /** @return array<string, mixed> */
    private function pageOptions(int|float $heightMm): array
    {
        return [
            'format' => [self::PAGE_WIDTH_MM, $heightMm],
            'margin_top' => self::MARGIN_MM,
            'margin_bottom' => self::MARGIN_MM,
            'margin_left' => self::MARGIN_MM,
            'margin_right' => self::MARGIN_MM,
        ];
    }
}
