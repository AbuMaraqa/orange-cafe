<?php

namespace Tests\Feature;

use App\Services\PdfService;
use App\Settings\RestaurantSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Tests\TestCase;

/**
 * PDFs must work on a machine that has never produced one.
 *
 * mPDF writes to a scratch directory for every document and throws if it is
 * missing — but it never creates it. On a developer's machine the folder is
 * left over from earlier runs and is gitignored, so this never showed up
 * locally. On a fresh packaged install NativePHP relocates storage to the
 * user's data directory and creates only logs/ and framework/, so every
 * invoice and every thermal ticket died with a bare 500.
 */
class PdfWorksOnAFreshInstallTest extends TestCase
{
    use RefreshDatabase;

    private function removeScratchDirs(): void
    {
        // Only mpdf-tmp: storage/fonts holds the real Tajawal/Almarai
        // files the brand fonts are rendered from, and a test that deletes
        // them breaks every Arabic PDF on the machine it runs on.
        foreach ([storage_path('app/mpdf-tmp')] as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            // Recursive and including dotfiles: mPDF leaves subdirectories
            // and a .gitignore behind, and rmdir on a non-empty directory
            // fails silently — leaving the test asserting against a state it
            // never actually reached.
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }

            @rmdir($dir);
        }
    }

    public function test_a_pdf_is_produced_when_the_scratch_directory_is_missing(): void
    {
        $this->removeScratchDirs();

        $this->assertDirectoryDoesNotExist(storage_path('app/mpdf-tmp'), 'precondition: the fresh-install state');

        $mpdf = app(PdfService::class)->make();

        $this->assertDirectoryExists(storage_path('app/mpdf-tmp'), 'the service must create what mPDF needs');
        $this->assertInstanceOf(Mpdf::class, $mpdf);
    }

    public function test_it_renders_a_view_to_pdf_bytes(): void
    {
        $this->removeScratchDirs();

        $mpdf = app(PdfService::class)->fromView('pdf.wifi-qr', [
            'branding' => app(RestaurantSettings::class),
            'qrCode' => null,
            'labels' => __('pdf'),
        ]);

        $bytes = $mpdf->Output('t.pdf', Destination::STRING_RETURN);

        $this->assertGreaterThan(500, strlen($bytes), 'a real PDF, not an empty string');
        $this->assertStringStartsWith('%PDF', $bytes);
    }

    public function test_running_twice_is_fine(): void
    {
        $this->removeScratchDirs();

        app(PdfService::class)->make();
        $second = app(PdfService::class)->make();

        $this->assertInstanceOf(Mpdf::class, $second);
    }
}
