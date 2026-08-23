<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\PdfService;
use App\Settings\RestaurantSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mpdf\Output\Destination;
use Tests\TestCase;

/**
 * A missing brand font must never cost the kitchen its ticket.
 *
 * The brand fonts are committed to storage/fonts and packaged with the app,
 * but nothing installs them into the *relocated* storage: NativePHP moves
 * storage/ to the user's data directory on the customer's machine and
 * creates only logs/, framework/cache/ and framework/sessions/ there.
 *
 * mPDF resolves fonts at render time, and a font it cannot find is not a
 * downgrade — it is an exception. So on a fresh install every kitchen
 * ticket and every invoice died before reaching the printer, while the
 * plain-text test page printed perfectly and made the printer look fine.
 * Two facts that together point at everything except the actual cause.
 *
 * Two defences, one test each: the shipped copy is always on the search
 * path, and a font that is genuinely absent falls back instead of throwing.
 */
class TicketsPrintWithoutTheBrandFontTest extends TestCase
{
    use RefreshDatabase;

    private function ticketData(): array
    {
        $order = new Order(['guest_count' => 2]);
        $order->forceFill(['reference' => '2608211234567']);
        $order->created_at = now();
        $order->setRelation('table', null);

        $item = new OrderItem(['product_name' => 'شاورما عربي', 'quantity' => 2]);
        $item->setRelation('modifiers', collect());
        $item->setRelation('product', new Product(['prep_time_minutes' => null]));

        return [
            'order' => $order,
            'items' => collect([$item]),
            'labels' => __('pdf'),
            'notice' => null,
        ];
    }

    private function renderTicket(): string
    {
        $mpdf = app(PdfService::class)->fromView('pdf.kitchen-ticket', $this->ticketData(), 'rtl', [
            'format' => [72, 120],
        ]);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    public function test_the_shipped_font_directory_is_searched(): void
    {
        // base_path('storage/fonts') is the copy that ships inside the app
        // and is never relocated. Without it on the search path, the
        // packaged app looks only where the fonts are not.
        $this->assertTrue(
            app(PdfService::class)->fontIsInstalled('tajawal'),
            'The brand font should be findable through the shipped path.'
        );
    }

    public function test_a_ticket_still_renders_when_the_brand_font_is_missing(): void
    {
        // A font nobody ever installed. Asking mPDF for it used to throw
        // mid-render and take the ticket with it.
        app(RestaurantSettings::class)->fill(['font_family' => 'tajawal'])->save();

        $pdf = $this->renderTicket();

        $this->assertStringStartsWith('%PDF', $pdf, 'The ticket has to come out, whatever face it is set in.');
    }

    public function test_an_unknown_font_setting_falls_back_rather_than_failing(): void
    {
        app(RestaurantSettings::class)->fill(['font_family' => 'system'])->save();

        $this->assertStringStartsWith('%PDF', $this->renderTicket());
    }

    public function test_the_font_check_says_no_to_a_font_that_is_not_there(): void
    {
        $this->assertFalse(
            app(PdfService::class)->fontIsInstalled('a-font-nobody-shipped'),
            'A font with no file must report absent, or the guard passes and mPDF throws.'
        );
    }
}
