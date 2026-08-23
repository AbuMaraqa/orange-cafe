<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Notes are instructions, and the kitchen ticket is where they have to land.
 *
 * Two things were wrong. A note on an item printed at 12px italic under a
 * 15px bold dish name — on 80mm thermal paper, under kitchen lighting, the
 * line a cook's eye skips first. And a note on the whole order never printed
 * at all: a customer asking for everything without onions, or a waiter
 * flagging an allergy, wrote it down and nobody cooking ever saw it.
 */
class KitchenTicketShowsNotesTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(?string $orderNote, ?string $itemNote): string
    {
        $order = Order::create([
            'status' => 'confirmed',
            'locale' => 'ar',
            'total_cents' => 1500,
            'notes' => $orderNote,
        ]);

        $order->items()->create([
            'product_name' => 'برجر دجاج',
            'station' => 'ماكولات',
            'unit_price_cents' => 1500,
            'quantity' => 2,
            'subtotal_cents' => 3000,
            'notes' => $itemNote,
        ]);

        $order = $order->fresh(['items.modifiers', 'table']);

        return View::make('pdf.kitchen-ticket', [
            'order' => $order,
            'items' => $order->items,
            'labels' => __('pdf'),
            'notice' => null,
        ])->render();
    }

    public function test_an_order_note_reaches_the_kitchen(): void
    {
        $html = $this->ticket('بدون بصل على كل الطلبية', null);

        $this->assertStringContainsString('بدون بصل على كل الطلبية', $html);
        $this->assertStringContainsString('<div class="order-notes">', $html, 'it must use the prominent style');
    }

    public function test_an_item_note_is_printed_and_labelled(): void
    {
        $html = $this->ticket(null, 'مشوي جيداً');

        $this->assertStringContainsString('مشوي جيداً', $html);
        $this->assertStringContainsString(__('pdf.note'), $html);
    }

    public function test_both_notes_appear_together(): void
    {
        $html = $this->ticket('زبون حساس للمكسرات', 'بدون صلصة');

        $this->assertStringContainsString('زبون حساس للمكسرات', $html);
        $this->assertStringContainsString('بدون صلصة', $html);
    }

    /**
     * The order note comes before the items: it applies to all of them, so it
     * has to be read before any cooking starts.
     */
    public function test_the_order_note_comes_before_the_items(): void
    {
        $html = $this->ticket('عاجل', 'بدون صلصة');

        $this->assertLessThan(
            strpos($html, 'برجر دجاج'),
            strpos($html, 'عاجل'),
            'the order-wide instruction must be above the dishes'
        );
    }

    public function test_a_ticket_without_notes_prints_no_empty_boxes(): void
    {
        $html = $this->ticket(null, null);

        $this->assertStringNotContainsString('<div class="order-notes">', $html);
        $this->assertStringContainsString('برجر دجاج', $html);
    }

    /**
     * Whitespace is not a note — a blank box on every ticket trains the
     * kitchen to ignore the box.
     */
    public function test_a_whitespace_only_note_is_not_printed(): void
    {
        $html = $this->ticket('   ', '  ');

        $this->assertStringNotContainsString('<div class="order-notes">', $html);
    }
}
