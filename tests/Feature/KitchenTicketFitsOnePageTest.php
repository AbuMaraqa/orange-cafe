<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\Product;
use App\Services\PdfService;
use App\Services\Printing\EscPosImageRenderer;
use App\Services\Printing\PopplerBinary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The ticket the kitchen receives has to contain the whole order.
 *
 * It is rasterized with `pdftoppm -singlefile`, which converts the first
 * page and only the first page. So if the ticket's content ever spills
 * onto a second one, the dishes on it are not late or misprinted — they
 * simply never reach the kitchen, and nothing anywhere says so: the print
 * job is recorded as printed, the paper looks like a whole ticket, and the
 * table waits for food nobody was told to cook.
 *
 * The page height used to be estimated from the item count
 * (`40 + count * 16` mm), which no note, modifier line or wrapped dish
 * name was included in — four items with notes needed 156mm and were given
 * 104. These tests pin the invariant that replaced it: whatever height the
 * renderer picks, everything fits inside it.
 */
class KitchenTicketFitsOnePageTest extends TestCase
{
    // The ticket is set in the brand font, which PdfService reads from the
    // settings table.
    use RefreshDatabase;

    /** Dots per inch the renderer rasterizes at, to read mm back off the PNG. */
    private const DPI = 203;

    private const PAGE_WIDTH_MM = 72;

    private const MARGIN_MM = 3;

    protected function setUp(): void
    {
        parent::setUp();

        if (app(PopplerBinary::class)->path() === null) {
            $this->markTestSkipped('pdftoppm (poppler) is not available, so no ticket can be rasterized.');
        }
    }

    public function test_a_plain_ticket_fits_on_one_page(): void
    {
        $this->assertTicketFits($this->items(3));
    }

    public function test_a_ticket_whose_items_all_carry_notes_fits_on_one_page(): void
    {
        // The case that was truncated in production: the estimate gave four
        // items 104mm and they needed 156.
        $this->assertTicketFits($this->items(4, notes: true, modifiers: true));
    }

    public function test_a_long_order_with_an_order_note_fits_on_one_page(): void
    {
        $this->assertTicketFits(
            $this->items(12, notes: true, modifiers: true),
            orderNote: 'الطلبية كلها بدون بصل من فضلك، والزبون عنده حساسية من المكسرات',
        );
    }

    public function test_a_ticket_grows_with_what_is_on_it(): void
    {
        // A dropped tail is invisible in the bytes, so this measures the
        // thing that used to be clamped: content the estimate ignored has
        // to lengthen the paper, not overflow it.
        $plain = $this->ticketHeightMm($this->items(4));
        $annotated = $this->ticketHeightMm($this->items(4, notes: true, modifiers: true));

        $this->assertGreaterThan(
            $plain,
            $annotated,
            'Notes and modifiers add height to a ticket; if they do not, they are being cut off it.'
        );
    }

    /**
     * Renders the ticket, then re-lays the same content out on the page
     * height the renderer chose and asserts mPDF keeps it to one page —
     * which is the whole of what `-singlefile` will convert.
     */
    private function assertTicketFits(Collection $items, ?string $orderNote = null): void
    {
        $data = $this->viewData($items, $orderNote);
        $heightMm = $this->ticketHeightMm($items, $orderNote);

        $mpdf = app(PdfService::class)->fromView('pdf.kitchen-ticket', $data, 'rtl', [
            'format' => [self::PAGE_WIDTH_MM, $heightMm],
            'margin_top' => self::MARGIN_MM,
            'margin_bottom' => self::MARGIN_MM,
            'margin_left' => self::MARGIN_MM,
            'margin_right' => self::MARGIN_MM,
        ]);

        $this->assertCount(
            1,
            $mpdf->pages,
            "The ticket spilled onto a second page at {$heightMm}mm — everything after the first page would be missing from the kitchen's copy."
        );
    }

    /** The height of the paper the renderer actually produced, in mm. */
    private function ticketHeightMm(Collection $items, ?string $orderNote = null): float
    {
        $renderer = new class(app(PdfService::class), app(PopplerBinary::class)) extends EscPosImageRenderer
        {
            public string $png = '';

            protected function renderImage(string $view, array $data, string $direction): string
            {
                $path = parent::renderImage($view, $data, $direction);

                // Kept beyond render()'s own cleanup so its dimensions can
                // be read after the fact.
                $this->png = $path.'.kept.png';
                copy($path, $this->png);

                return $path;
            }
        };

        $renderer->render('pdf.kitchen-ticket', $this->viewData($items, $orderNote), 'rtl');

        try {
            [, $heightPx] = getimagesize($renderer->png);

            return $heightPx / self::DPI * 25.4;
        } finally {
            @unlink($renderer->png);
        }
    }

    /** @return array<string, mixed> */
    private function viewData(Collection $items, ?string $orderNote = null): array
    {
        $order = new Order(['guest_count' => 4, 'notes' => $orderNote]);
        $order->forceFill(['reference' => '2608211234567']);
        $order->created_at = now();
        $order->setRelation('table', null);

        return [
            'order' => $order,
            'items' => $items,
            'labels' => __('pdf'),
            'notice' => null,
        ];
    }

    /**
     * Unsaved models: this is about layout, and building them in memory
     * keeps the test away from the database entirely.
     *
     * @return Collection<int, OrderItem>
     */
    private function items(int $count, bool $notes = false, bool $modifiers = false): Collection
    {
        return collect(range(1, $count))->map(function (int $i) use ($notes, $modifiers) {
            $item = new OrderItem([
                // Long enough to wrap at 72mm, which is one of the things
                // an item count cannot predict.
                'product_name' => "صنف رقم {$i} شاورما دجاج سبيشال كبير",
                'quantity' => 2,
                'notes' => $notes ? 'بدون بصل وبدون مايونيز، حار جدا' : null,
            ]);

            $item->setRelation('modifiers', $modifiers
                ? collect([new OrderItemModifier(['modifier_name' => 'جبنة إضافية'])])
                : collect());

            $item->setRelation('product', new Product(['prep_time_minutes' => 15]));

            return $item;
        });
    }
}
