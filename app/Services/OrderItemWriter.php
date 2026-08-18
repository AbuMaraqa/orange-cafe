<?php

namespace App\Services;

use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Resolves order line(s) — menu item pricing (with its per-menu override),
 * station routing, and modifier selection/validation — into persisted
 * OrderItem rows. Shared by placing a brand-new order and by adding more
 * items to an already-running one, so pricing/availability rules and the
 * order-time price snapshot behave identically in both places.
 */
class OrderItemWriter
{
    /**
     * @param  array<int, array{menu_item_id:int, quantity:int, notes?:?string, modifier_ids?:array}>  $lines
     * @return int total cents added across all lines
     */
    public function addLines(Order $order, array $lines): int
    {
        $addedCents = 0;

        foreach ($lines as $line) {
            $menuItem = MenuItem::with('product.modifierGroups.modifiers', 'product.modifierOverrides', 'category')->findOrFail($line['menu_item_id']);
            $product = $menuItem->product;

            if (! $menuItem->is_active || ! $product->is_active) {
                throw ValidationException::withMessages(['items' => __('menu.product_unavailable', ['name' => $product->name])]);
            }

            $modifiers = $this->resolveModifiers($product, $line['modifier_ids'] ?? []);
            // A modifier's price can be overridden per product (see
            // Product::effectiveModifierPriceCents), so this — not the
            // modifier's own price_cents — is the authoritative amount.
            $unitPrice = $menuItem->effectivePriceCents()
                + $modifiers->sum(fn (Modifier $modifier) => $product->effectiveModifierPriceCents($modifier));
            $subtotal = $unitPrice * $line['quantity'];
            $addedCents += $subtotal;

            $item = $order->items()->create([
                'product_id' => $product->id,
                'menu_item_id' => $menuItem->id,
                'product_name' => $product->name,
                'station' => $menuItem->effectiveStation(),
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
                    'price_cents' => $product->effectiveModifierPriceCents($modifier),
                ]);
            }
        }

        return $addedCents;
    }

    /**
     * @return Collection<int, Modifier>
     */
    protected function resolveModifiers(Product $product, array $modifierIds): Collection
    {
        $availableModifiers = $product->modifierGroups->flatMap->modifiers
            ->filter(fn (Modifier $modifier) => $product->isModifierActiveForProduct($modifier))
            ->keyBy('id');
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
}
