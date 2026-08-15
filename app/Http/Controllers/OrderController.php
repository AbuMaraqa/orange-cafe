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
