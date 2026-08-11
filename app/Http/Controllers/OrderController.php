<?php

namespace App\Http\Controllers;

use App\Events\OrderPlaced;
use App\Http\Requests\StoreOrderRequest;
use App\Jobs\PrintOrderTicket;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function store(StoreOrderRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $order = DB::transaction(function () use ($data) {
            $order = Order::create([
                'table_id' => $data['table_id'] ?? null,
                'table_number' => $data['table_number'] ?? null,
                'guest_count' => $data['guest_count'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => config('orders.default_status'),
                'locale' => app()->getLocale(),
                'total_cents' => 0,
            ]);

            $total = 0;

            foreach ($data['items'] as $line) {
                $product = Product::with('modifierGroups.modifiers')->findOrFail($line['product_id']);

                if (! $product->is_active) {
                    throw ValidationException::withMessages(['items' => __('menu.product_unavailable', ['name' => $product->name])]);
                }

                $modifiers = $this->resolveModifiers($product, $line['modifier_ids'] ?? []);
                $unitPrice = $product->price_cents + $modifiers->sum('price_cents');
                $subtotal = $unitPrice * $line['quantity'];
                $total += $subtotal;

                $item = $order->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'station' => $product->effectiveStation(),
                    'unit_price_cents' => $unitPrice,
                    'quantity' => $line['quantity'],
                    'subtotal_cents' => $subtotal,
                    'notes' => $line['notes'] ?? null,
                ]);

                foreach ($modifiers as $modifier) {
                    $item->modifiers()->create([
                        'modifier_id' => $modifier->id,
                        'modifier_group_name' => $modifier->group->name,
                        'modifier_name' => $modifier->name,
                        'price_cents' => $modifier->price_cents,
                    ]);
                }
            }

            $order->update(['total_cents' => $total]);

            return $order;
        });

        $this->safeBroadcast(new OrderPlaced($order));
        PrintOrderTicket::dispatch($order);

        return to_route('menu.orders.show', $order)->with('success', __('menu.order_placed'));
    }

    /**
     * A customer's checkout must never fail just because the Reverb server
     * (or any broadcaster) happens to be down — real-time staff
     * notifications are a nice-to-have on top of a placed order, not a
     * precondition for it.
     */
    protected function safeBroadcast($event): void
    {
        try {
            broadcast($event);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function show(Order $order): Response
    {
        $order->load('items.modifiers', 'table');

        return Inertia::render('Customer/OrderStatus', [
            'order' => $this->present($order),
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, Modifier>
     */
    protected function resolveModifiers(Product $product, array $modifierIds): \Illuminate\Support\Collection
    {
        $availableModifiers = $product->modifierGroups->flatMap->modifiers->keyBy('id');
        $selected = collect($modifierIds)->map(fn ($id) => $availableModifiers->get($id))->filter();

        if ($selected->count() !== count($modifierIds)) {
            throw ValidationException::withMessages(['items' => __('menu.invalid_modifier_selection')]);
        }

        foreach ($product->modifierGroups as $group) {
            $chosen = $selected->filter(fn (Modifier $modifier) => $modifier->modifier_group_id === $group->id)->count();

            if ($group->is_required && $chosen < max($group->min_select, 1)) {
                throw ValidationException::withMessages(['items' => __('menu.modifier_group_required', ['group' => $group->name])]);
            }

            if ($chosen < $group->min_select) {
                throw ValidationException::withMessages(['items' => __('menu.modifier_group_min', ['group' => $group->name, 'min' => $group->min_select])]);
            }

            if ($group->max_select !== null && $chosen > $group->max_select) {
                throw ValidationException::withMessages(['items' => __('menu.modifier_group_max', ['group' => $group->name, 'max' => $group->max_select])]);
            }
        }

        return $selected->values();
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
