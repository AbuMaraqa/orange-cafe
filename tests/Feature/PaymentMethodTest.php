<?php

namespace Tests\Feature;

use App\Jobs\OpenCashDrawer;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Printer;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Services\Accounting\Cashbox;
use App\Settings\RestaurantSettings;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Cash or card, and what each one does to the drawer.
 *
 * Every takings figure in this application used to rest on an unstated
 * assumption: money handed over is money in the till. That was true while
 * cash was the only way to pay, and it stops being true the moment a card
 * reader is on the counter — a card settles the bill and the drawer never
 * opens.
 *
 * So the interesting cases here are not "does the method get saved". They are
 * the ones where treating a card like cash produces a figure that is wrong in
 * a way nobody notices until the drawer is counted:
 *
 *  - the day's balance, which a cashier signs their name against;
 *  - the float carried into tomorrow, which inherits any error silently;
 *  - the movement list, which is read precisely when the balance is doubted.
 *
 * And one that is not about money at all: a bill has to be able to say how it
 * was paid, on the screen and on the customer's copy, because "was that on
 * the card?" is the question a closed table actually gets asked.
 */
class PaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(LanguageSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    private function cashier(): User
    {
        $user = User::create([
            'name' => 'كاشير',
            'email' => 'c'.fake()->unique()->numberBetween(1, 99999).'@test.local',
            'password' => 'password',
            'locale' => 'ar',
            'email_verified_at' => now(),
        ]);
        $user->assignRole('cashier');

        return $user;
    }

    private function billOf(int $totalCents): Order
    {
        $table = RestaurantTable::create([
            'label' => (string) fake()->unique()->numberBetween(1, 9999),
            'seats' => 4,
            'is_active' => true,
        ]);

        $order = Order::create([
            'table_id' => $table->id,
            'status' => 'confirmed',
            'locale' => 'ar',
            'total_cents' => $totalCents,
        ]);

        $order->items()->create([
            'product_name' => 'قهوة',
            'station' => 'kitchen',
            'unit_price_cents' => $totalCents,
            'quantity' => 1,
            'subtotal_cents' => $totalCents,
        ]);

        return $order->fresh('items');
    }

    private function tillPrinter(): Printer
    {
        return Printer::create([
            'name' => 'till',
            'station' => 'cashier',
            'connection_type' => 'network',
            'ip_address' => '10.0.0.'.random_int(2, 250),
            'port' => 9100,
            'is_active' => true,
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    private function pay(Order $order, array $payload)
    {
        Queue::fake();

        return $this->actingAs($this->cashier())
            ->patch(route('orders.pay', $order), $payload);
    }

    private function cashbox(): Cashbox
    {
        return app(Cashbox::class);
    }

    /* ------------------------------------------------------------------ *
     * Recording it
     * ------------------------------------------------------------------ */

    public function test_a_card_payment_records_its_method_and_reference(): void
    {
        $order = $this->billOf(5000);

        $this->pay($order, [
            'amount_paid_cents' => 5000,
            'payment_method' => OrderPayment::METHOD_CARD,
            'payment_reference' => 'TXN-77213',
        ])->assertSessionHasNoErrors();

        $order->refresh();

        $this->assertSame(OrderPayment::METHOD_CARD, $order->payment_method);
        $this->assertSame('TXN-77213', $order->payment_reference);

        // The timeline is what the cash box reads, so the method has to be on
        // the handover itself and not only on the bill summarising it.
        $payment = $order->payments()->sole();
        $this->assertSame(OrderPayment::METHOD_CARD, $payment->method);
        $this->assertSame('TXN-77213', $payment->reference);
        $this->assertSame(5000, $payment->amount_cents);
    }

    /**
     * Everything that reached this endpoint before the card reader existed
     * meant cash, and still has to — the importer and the seeders included.
     */
    public function test_a_payment_that_says_nothing_is_cash(): void
    {
        $order = $this->billOf(3000);

        $this->pay($order, ['amount_paid_cents' => 3000]);

        $this->assertSame(OrderPayment::METHOD_CASH, $order->fresh()->payment_method);
        $this->assertSame(OrderPayment::METHOD_CASH, $order->payments()->sole()->method);
    }

    /**
     * Worth asking for; not worth refusing money over.
     *
     * It was required at first, on the argument that a charge nobody can match
     * against the terminal's own settlement is a charge nobody can check. The
     * argument holds and the rule still had to go: the cashier is standing in
     * front of a customer, and a till that will not close a paid bill until a
     * number has been copied off a second screen gets answered with "1" every
     * time — which looks like a record and is not one.
     */
    public function test_a_card_payment_without_a_reference_is_taken(): void
    {
        $order = $this->billOf(5000);

        $this->pay($order, [
            'amount_paid_cents' => 5000,
            'payment_method' => OrderPayment::METHOD_CARD,
        ])->assertSessionHasNoErrors();

        $order->refresh();

        $this->assertNotNull($order->paid_at, 'the bill closes');
        $this->assertSame(OrderPayment::METHOD_CARD, $order->payment_method);
        $this->assertNull($order->payment_reference);

        // The part that was never about the reference: the money still has to
        // be known as card money, or the drawer is counted against it.
        $payment = $order->payments()->sole();
        $this->assertSame(OrderPayment::METHOD_CARD, $payment->method);
        $this->assertNull($payment->reference);
        $this->assertSame(5000, $this->cashbox()->day($this->cashbox()->today())['card_collected_cents']);
    }

    /**
     * A box the cashier tabbed through is empty, not blank.
     *
     * An empty string in the column is a reference that matches nothing and
     * looks like one that should, which is the state this feature spends its
     * comments trying to avoid.
     */
    public function test_a_reference_left_blank_is_stored_as_nothing(): void
    {
        $order = $this->billOf(5000);

        $this->pay($order, [
            'amount_paid_cents' => 5000,
            'payment_method' => OrderPayment::METHOD_CARD,
            'payment_reference' => '',
        ])->assertSessionHasNoErrors();

        $this->assertNull($order->fresh()->payment_reference);
        $this->assertNull($order->payments()->sole()->reference);
    }

    /** Cash needs no reference, and must not be made to invent one. */
    public function test_a_cash_payment_needs_no_reference(): void
    {
        $order = $this->billOf(5000);

        $this->pay($order, [
            'amount_paid_cents' => 5000,
            'payment_method' => OrderPayment::METHOD_CASH,
        ])->assertSessionHasNoErrors();

        $this->assertNotNull($order->fresh()->paid_at);
    }

    /**
     * A reference belongs to the charge it describes.
     *
     * Allowed to leak onto a cash handover it would claim a card transaction
     * twice, and somebody tracing a line on a statement would find two bills
     * asserting it was theirs.
     */
    public function test_a_reference_sent_with_a_cash_payment_is_discarded(): void
    {
        $order = $this->billOf(5000);

        $this->pay($order, [
            'amount_paid_cents' => 5000,
            'payment_method' => OrderPayment::METHOD_CASH,
            'payment_reference' => 'TXN-STALE',
        ]);

        $this->assertNull($order->fresh()->payment_reference);
        $this->assertNull($order->payments()->sole()->reference);
    }

    /* ------------------------------------------------------------------ *
     * The drawer
     * ------------------------------------------------------------------ */

    public function test_a_card_payment_does_not_open_the_drawer(): void
    {
        $this->tillPrinter();

        $this->pay($this->billOf(5000), [
            'amount_paid_cents' => 5000,
            'payment_method' => OrderPayment::METHOD_CARD,
            'payment_reference' => 'TXN-1',
        ]);

        Queue::assertNotPushed(OpenCashDrawer::class);
    }

    public function test_a_cash_payment_still_opens_the_drawer(): void
    {
        $this->tillPrinter();

        $this->pay($this->billOf(5000), ['amount_paid_cents' => 5000]);

        Queue::assertPushed(OpenCashDrawer::class);
    }

    /* ------------------------------------------------------------------ *
     * The cash box
     * ------------------------------------------------------------------ */

    /**
     * The failure this whole feature exists to avoid: a till counted honestly
     * and reported short by the day's card trade.
     */
    public function test_a_card_payment_is_not_in_the_drawer_balance(): void
    {
        $today = $this->cashbox()->today();

        $this->pay($this->billOf(4000), ['amount_paid_cents' => 4000]);

        $this->pay($this->billOf(6000), [
            'amount_paid_cents' => 6000,
            'payment_method' => OrderPayment::METHOD_CARD,
            'payment_reference' => 'TXN-2',
        ]);

        $day = $this->cashbox()->day($today);

        // The drawer holds the float and the cash bill, and nothing else.
        $this->assertSame(4000, $day['collected_cents']);
        $this->assertSame($this->cashbox()->openingCents($today) + 4000, $day['balance_cents']);

        // The card money is still the café's, and still reported.
        $this->assertSame(6000, $day['card_collected_cents']);
        $this->assertSame(10000, $day['total_takings_cents']);
    }

    /**
     * The movement list is read when the balance is being questioned, so a
     * line in it the balance does not contain is worse than no line at all.
     */
    public function test_the_movement_list_still_adds_up_to_the_balance(): void
    {
        $today = $this->cashbox()->today();

        $this->pay($this->billOf(4000), ['amount_paid_cents' => 4000]);
        $this->pay($this->billOf(6000), [
            'amount_paid_cents' => 6000,
            'payment_method' => OrderPayment::METHOD_CARD,
            'payment_reference' => 'TXN-3',
        ]);

        $movements = $this->cashbox()->movements($today);
        $day = $this->cashbox()->day($today);

        $listed = collect($movements)->sum(
            fn (array $row) => $row['direction'] === 'in' ? $row['amount_cents'] : -$row['amount_cents']
        );

        $this->assertSame($day['net_movement_cents'], $listed);
        $this->assertCount(1, $movements);
    }

    /**
     * Tomorrow opens on the float the owner leaves in the drawer, which a
     * card payment tonight never touched.
     */
    public function test_a_card_payment_is_not_carried_into_tomorrows_float(): void
    {
        $cashbox = $this->cashbox();
        $tomorrow = Carbon::parse($cashbox->today())->addDay()->toDateString();

        $this->pay($this->billOf(6000), [
            'amount_paid_cents' => 6000,
            'payment_method' => OrderPayment::METHOD_CARD,
            'payment_reference' => 'TXN-4',
        ]);

        $this->assertSame(app(RestaurantSettings::class)->cashbox_float_cents, $cashbox->openingCents($tomorrow));
    }

    /* ------------------------------------------------------------------ *
     * A bill paid two ways
     * ------------------------------------------------------------------ */

    /**
     * Half in cash, the rest on a card.
     *
     * Each handover keeps its own method — that is what the cash box splits
     * on — while the bill names the last one, exactly as `paid_by` names the
     * last cashier.
     */
    public function test_a_bill_split_between_cash_and_card_keeps_both(): void
    {
        $today = $this->cashbox()->today();
        $order = $this->billOf(10000);

        $this->pay($order, ['amount_paid_cents' => 4000, 'partial' => true]);

        $this->pay($order, [
            'amount_paid_cents' => 10000,
            'payment_method' => OrderPayment::METHOD_CARD,
            'payment_reference' => 'TXN-5',
        ]);

        $order->refresh()->load('payments');

        $this->assertTrue($order->isPaid());
        $this->assertSame(10000, $order->amount_paid_cents);
        $this->assertSame(OrderPayment::METHOD_CARD, $order->payment_method);

        // Two handovers, one of each, and the second only the remainder.
        $this->assertSame(
            [OrderPayment::METHOD_CASH => 4000, OrderPayment::METHOD_CARD => 6000],
            $order->collectedByMethod()
        );

        $this->assertSame(['TXN-5'], $order->cardReferences());

        // The drawer took the four thousand and not an agora more.
        $day = $this->cashbox()->day($today);
        $this->assertSame(4000, $day['collected_cents']);
        $this->assertSame(6000, $day['card_collected_cents']);
    }

    /* ------------------------------------------------------------------ *
     * Saying so
     * ------------------------------------------------------------------ */

    public function test_the_receipt_says_how_the_bill_was_paid(): void
    {
        $order = $this->billOf(5000);

        $this->pay($order, [
            'amount_paid_cents' => 5000,
            'payment_method' => OrderPayment::METHOD_CARD,
            'payment_reference' => 'TXN-90210',
        ]);

        $order->refresh()->load('items.modifiers', 'table', 'payments');

        $html = view('pdf.invoice-thermal', [
            'order' => $order,
            'branding' => app(RestaurantSettings::class),
            'logoPath' => null,
            'currencySymbol' => '₪',
            'direction' => 'rtl',
            'notice' => null,
            'labels' => __('pdf', [], 'ar'),
        ])->render();

        $this->assertStringContainsString(__('pdf.payment_methods.card', [], 'ar'), $html);
        $this->assertStringContainsString('TXN-90210', $html);

        // And does not also claim it was cash — the bill names one method.
        $this->assertStringNotContainsString(__('pdf.payment_methods.cash', [], 'ar'), $html);
    }

    public function test_the_screens_are_told_how_a_table_paid(): void
    {
        $order = $this->billOf(5000);

        $this->pay($order, [
            'amount_paid_cents' => 5000,
            'payment_method' => OrderPayment::METHOD_CARD,
            'payment_reference' => 'TXN-6',
        ]);

        $this->actingAs($this->cashier())
            ->get(route('cashier.index'))
            ->assertInertia(fn ($page) => $page
                ->where('tables.0.active_order.payment_method', OrderPayment::METHOD_CARD)
                ->where('tables.0.active_order.payment_reference', 'TXN-6')
            );
    }
    /* ------------------------------------------------------------------ *
     * The report
     * ------------------------------------------------------------------ */

    private function manager(): User
    {
        $user = User::create([
            'name' => 'مدير',
            'email' => 'm'.fake()->unique()->numberBetween(1, 99999).'@test.local',
            'password' => 'password',
            'locale' => 'ar',
            'email_verified_at' => now(),
        ]);
        $user->assignRole('admin');

        return $user;
    }

    private function report()
    {
        return $this->actingAs($this->manager())->get(route('admin.accounting.index'));
    }

    /**
     * "We took 900 today" is two different sentences depending on where the
     * money is, and the screen built to trace it has to say which.
     */
    public function test_the_daily_report_splits_what_was_collected_by_method(): void
    {
        $this->pay($this->billOf(4000), ['amount_paid_cents' => 4000]);
        $this->pay($this->billOf(6000), [
            'amount_paid_cents' => 6000,
            'payment_method' => OrderPayment::METHOD_CARD,
        ]);

        $this->report()->assertInertia(fn ($page) => $page
            ->where('summary.collected_cents', 10000)
            ->where('summary.cash_collected_cents', 4000)
            ->where('summary.card_collected_cents', 6000)
        );
    }

    /**
     * The reason the split is read off the payment timeline and not off the
     * bill's own column: the column keeps the last handover, so a table that
     * paid most of its bill in cash and finished on a card would file all of
     * it under card and report a drawer four thousand heavier than it is.
     */
    public function test_a_bill_paid_two_ways_is_split_rather_than_filed_under_its_last_method(): void
    {
        $order = $this->billOf(10000);

        $this->pay($order, ['amount_paid_cents' => 4000, 'partial' => true]);
        $this->pay($order, [
            'amount_paid_cents' => 10000,
            'payment_method' => OrderPayment::METHOD_CARD,
        ]);

        $this->report()->assertInertia(fn ($page) => $page
            ->where('summary.cash_collected_cents', 4000)
            ->where('summary.card_collected_cents', 6000)
        );
    }

    /**
     * A café that has never seen a card gets no split at all — the halves are
     * there, and the screen decides not to draw a line reading "card 0.00"
     * every day until the day it would have mattered.
     */
    public function test_a_cash_only_day_reports_no_card_money(): void
    {
        $this->pay($this->billOf(4000), ['amount_paid_cents' => 4000]);

        $this->report()->assertInertia(fn ($page) => $page
            ->where('summary.cash_collected_cents', 4000)
            ->where('summary.card_collected_cents', 0)
        );
    }

    public function test_each_bill_in_the_report_says_how_it_was_paid(): void
    {
        $this->pay($this->billOf(6000), [
            'amount_paid_cents' => 6000,
            'payment_method' => OrderPayment::METHOD_CARD,
            'payment_reference' => 'TXN-7',
        ]);

        $this->report()->assertInertia(fn ($page) => $page
            ->where('orders.0.payment_method', OrderPayment::METHOD_CARD)
            ->where('orders.0.payment_reference', 'TXN-7')
        );
    }
}
