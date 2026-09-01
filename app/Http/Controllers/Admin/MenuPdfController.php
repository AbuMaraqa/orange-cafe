<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\LanguageRegistry;
use App\Services\MenuCatalogPresenter;
use App\Services\PdfService;
use App\Settings\RestaurantSettings;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Mpdf\Output\Destination;

/**
 * The menu as a sheet of paper — for the customer who is not in the room.
 *
 * Every other way this catalog reaches a customer assumes they are sitting
 * at a table with a QR code in front of them. The ones who ring up and ask
 * what there is, or want something to forward to the people they are
 * ordering lunch for, have had nothing to send them. This is that: one file,
 * priced, sectioned, and carrying the restaurant's name and number.
 *
 * It is deliberately built from MenuCatalogPresenter — the same payload the
 * QR menu renders from — rather than its own query. A printed menu that
 * quietly disagrees with the live one about a price is worse than no printed
 * menu at all, and the only way to be sure it cannot is to read the same
 * source: the same "published, active, in schedule" resolution, the same
 * per-menu price overrides, the same active-only filtering.
 */
class MenuPdfController extends Controller
{
    public function __invoke(
        MenuCatalogPresenter $presenter,
        PdfService $pdf,
        RestaurantSettings $branding,
        LanguageRegistry $languages,
    ): Response {
        $direction = $languages->direction(app()->getLocale());

        // mPDF embeds images by local filesystem path far more reliably than
        // by fetching the public URL over HTTP — the same reason the invoice
        // resolves the disk path (see StaffOrderController::invoice). On the
        // packaged app there is no web server in front of storage at all.
        $logoPath = $branding->logo_path && Storage::disk('public')->exists($branding->logo_path)
            ? Storage::disk('public')->path($branding->logo_path)
            : null;

        $menus = $presenter->visibleMenusPayload();

        $mpdf = $pdf->fromView('pdf.customer-menu', [
            'menus' => $menus,
            'branding' => $branding,
            'logoPath' => $logoPath,
            'currencySymbol' => $branding->currency_symbol,
            'direction' => $direction,
            'labels' => __('pdf'),
        ], $direction, [
            'format' => 'A4',
            // Wide margins, deliberately. A4 is 210mm across and a dish is
            // three words; set to the full width of the sheet, "قهوة مثلجة"
            // and its price sat at opposite ends of a hand-span of blank
            // paper with nothing between them, and the eye lost the line
            // between the two. Every printed menu that reads well holds its
            // text in a narrower column than the page allows — 30mm a side
            // brings the measure to 150mm, which is about as wide as a line
            // can be before it stops being comfortable to scan.
            //
            // It costs nothing in pages: sections start on their own sheet
            // and most finish well short of the bottom, so the space this
            // takes was empty anyway.
            'margin_top' => 18,
            'margin_bottom' => 22,
            'margin_left' => 30,
            'margin_right' => 30,
            // Leaves the running footer room to sit clear of the last line
            // of food rather than crowding it.
            'margin_footer' => 8,
        ]);

        $filename = $this->filename($branding->name);

        // Inline, not an attachment. The person pressing this is usually
        // about to look at it before deciding to send it, and a browser tab
        // is a faster way to check the page breaks than a file in Downloads.
        return response($mpdf->Output($filename, Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    /**
     * A filename the customer's phone will not mangle.
     *
     * The restaurant's name is Arabic here more often than not, and an
     * Arabic filename survives the Content-Disposition header badly enough
     * that some clients save it as a string of percent-escapes. The date is
     * what makes the file useful once it has been forwarded a few times —
     * "which version of the prices is this?" is the question a saved menu
     * always ends up being asked.
     */
    private function filename(string $restaurantName): string
    {
        $slug = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $restaurantName), '-');

        return ($slug !== '' ? $slug : 'menu').'-'.now()->format('Y-m-d').'.pdf';
    }
}
