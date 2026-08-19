<?php

namespace App\Http\Controllers;

use App\Events\OrderPlaced;
use App\Http\Controllers\Concerns\BroadcastsSafely;
use App\Http\Requests\StoreOrderRequest;
use App\Models\Order;
use App\Services\KitchenPrinterService;
use App\Services\OrderItemWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    use BroadcastsSafely;

    public function __construct(protected OrderItemWriter $itemWriter, protected KitchenPrinterService $printer)
    {
    }

    public function store(StoreOrderRequest $request): RedirectResponse
    {
        $data = $request->validated();
        // A waiter typing an order in themselves has already effectively
        // reviewed it — it goes straight to the kitchen. A customer's own
        // order (from the QR menu) stays "pending" and unprinted until a
        // waiter reviews and confirms it (see StaffOrderController::confirm()).
        $placedByStaff = $request->user()?->hasAnyRole(['waiter', 'admin']) ?? false;

        // Five people sitting at one table each scan the same QR and order
        // from their own phone. Their items belong on that table's one open
        // ticket: five separate orders would reach the waiter as five things
        // to confirm, print as five kitchen tickets for one table, and bill
        // as five totals that somebody has to add up by hand.
        //
        // Only a ticket the waiter has not confirmed yet can be joined. Once
        // it is confirmed the kitchen is already cooking it, so a later
        // addition has to be a round of its own — it needs the waiter's eyes
        // and its own trip to the pass, not a silent edit to something that
        // has already been printed.
        if ($joinable = $this->joinableTableOrder($data, $placedByStaff)) {
            DB::transaction(function () use ($joinable, $data) {
                $added = $this->itemWriter->addLines($joinable, $data['items']);
                $joinable->update(['total_cents' => $joinable->total_cents + $added]);
            });

            $joinable->refresh();

            // Same event as a brand-new order on purpose: the waiter screen
            // upserts on it, so the table's card updates in place with the
            // added items and alerts whoever is on the floor. An addition
            // nobody notices is the same as an order nobody took.
            $this->safeBroadcast(new OrderPlaced($joinable));

            return to_route('menu.orders.show', $joinable)->with('success', __('menu.order_placed'));
        }

        $order = DB::transaction(function () use ($data, $request, $placedByStaff) {
            $order = Order::create([
                'table_id' => $data['table_id'] ?? null,
                'table_number' => $data['table_number'] ?? null,
                'guest_count' => $data['guest_count'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => $placedByStaff ? 'confirmed' : config('orders.default_status'),
                'locale' => app()->getLocale(),
                'created_by' => $request->user()?->id,
                'total_cents' => 0,
            ]);

            $total = $this->itemWriter->addLines($order, $data['items']);
            $order->update(['total_cents' => $total]);

            return $order;
        });

        $this->safeBroadcast(new OrderPlaced($order));

        if ($placedByStaff) {
            $this->printer->queuePrintJobs($order);

            // A waiter placing an order on a customer's behalf wants to get
            // straight back to the floor to keep working, not land on the
            // customer-facing order-status page.
            return to_route('waiter.index')->with('success', __('menu.order_placed'));
        }

        return to_route('menu.orders.show', $order)->with('success', __('menu.order_placed'));
    }

    /**
     * The table's open, not-yet-confirmed ticket that this customer's items
     * should join, or null when a new one has to be created.
     *
     * Staff are excluded: a waiter entering an order has already reviewed it
     * and it goes straight to the kitchen, so folding it into a pending
     * customer ticket would leave those items unprinted.
     *
     * @param  array<string, mixed>  $data
     */
    protected function joinableTableOrder(array $data, bool $placedByStaff): ?Order
    {
        if ($placedByStaff || empty($data['table_id'])) {
            return null;
        }

        return Order::query()
            ->where('table_id', $data['table_id'])
            ->where('status', 'pending')
            ->latest()
            ->first();
    }

    public function show(Order $order): Response
    {
        $order->load('items.modifiers', 'table');

        return Inertia::render('Customer/OrderStatus', [
            'order' => $this->present($order),
        ]);
    }

    protected function present(Order $order): array
    {
        return [
            'reference' => $order->reference,
            'status' => $order->status,
            'status_color' => $order->statusColor(),
            'table_label' => $order->table?->label ?? $order->table_number,
            'total_cents' => $order->total_cents,
            'is_paid' => $order->isPaid(),
            'created_at' => $order->created_at->toIso8601String(),
            'items' => $order->items->map(fn ($item) => [
                'product_name' => $item->product_name,
                'quantity' => $item->quantity,
                'unit_price_cents' => $item->unit_price_cents,
                'subtotal_cents' => $item->subtotal_cents,
                'notes' => $item->notes,
                'modifiers' => $item->modifiers->map(fn ($modifier) => [
                    'name' => $modifier->modifier_name,
                    'price_cents' => $modifier->price_cents,
                ]),
            ]),
        ];
    }
}
