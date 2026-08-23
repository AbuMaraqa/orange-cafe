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
     * An existing line on this order that the incoming one should merge into,
     * or null when it is genuinely a different thing.
     *
     * @param  Collection<int, Modifier>  $modifiers
     */
    protected function matchingLine(Order $order, MenuItem $menuItem, Collection $modifiers, ?string $notes)
    {
        $wanted = $modifiers->pluck('id')->sort()->values()->all();
        $notes = trim((string) $notes);

        return $order->items()
            ->with('modifiers')
            ->where('menu_item_id', $menuItem->id)
            ->get()
            ->first(function ($item) use ($wanted, $notes) {
                if (trim((string) $item->notes) !== $notes) {
                    return false;
                }

                $have = $item->modifiers->pluck('modifier_id')->sort()->values()->all();

                return $have === $wanted;
            });
    }

    /**
     * @param  array<int, array{menu_item_id:int, quantity:int, notes?:?string, modifier_ids?:array}>  $lines
     * @param  array<int, int>|null  $added  filled with [orderItemId => quantity added by this call]
     * @return int total cents added across all lines
     *
     * `$added` is a map rather than a list of ids because this method
     * merges: ordering two more of something already on the ticket bumps
     * that line to five rather than writing a second line of two. A caller
     * printing "what was just added" needs the two, not the five — send the
     * kitchen the line's new total and they cook the whole thing again.
     */
    public function addLines(Order $order, array $lines, ?array &$added = null): int
    {
        $addedCents = 0;
        $added = [];

        foreach ($lines as $line) {
            $menuItem = MenuItem::with('product.modifierGroups.modifiers', 'product.modifierOverrides', 'category')->findOrFail($line['menu_item_id']);
            $product = $menuItem->product;

            if (! $menuItem->is_active || ! $product->is_active) {
                throw ValidationException::withMessages(['items' => __('menu.product_unavailable', ['name' => $product->name])]);
            }

            $modifiers = $this->resolveModifiers($product, $line['modifier_ids'] ?? []);
            // Product::unitPriceCents owns the arithmetic — a variation
            // (small/medium/large) replaces the menu price, an extra adds to
            // it, and per-product price overrides are resolved inside. This
            // used to be a `+` written out here, which silently charged a
            // sized dish its base price *plus* the size.
            $unitPrice = $product->unitPriceCents($modifiers, $menuItem->effectivePriceCents());
            $subtotal = $unitPrice * $line['quantity'];
            $addedCents += $subtotal;

            // Ordering the same thing again adds to the line that is already
            // there. Two guests each asking for an orange juice, or one guest
            // adding a second later, is one line reading "×3" — not three
            // lines of "×1" that the kitchen has to add up, the waiter has to
            // read past, and the customer has to trust.
            //
            // "The same thing" means the same menu item with the same
            // modifiers and the same note: a juice with no ice is a different
            // drink from a juice with ice, and merging them would send the
            // kitchen an instruction nobody gave.
            $existing = $this->matchingLine($order, $menuItem, $modifiers, $line['notes'] ?? null);

            if ($existing) {
                $existing->quantity += $line['quantity'];
                // Recomputed from the stored unit price rather than added to,
                // so a line stays internally consistent even if it was
                // written before a price change.
                $existing->subtotal_cents = $existing->unit_price_cents * $existing->quantity;
                $existing->save();

                $added[$existing->id] = ($added[$existing->id] ?? 0) + $line['quantity'];

                continue;
            }

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

            $added[$item->id] = ($added[$item->id] ?? 0) + $line['quantity'];

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

            // A variation group is the product's price, so exactly one is
            // the only selection that means anything: none leaves the line
            // with no price at all, two would silently keep whichever came
            // first. Checked before the generic rules so the message names
            // the real problem.
            if ($group->is_variation && $chosen !== 1) {
                throw ValidationException::withMessages(['items' => __('menu.variation_required', ['group' => $group->name])]);
            }

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
