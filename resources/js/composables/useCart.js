import { computed, reactive, watch } from 'vue';
import { unitPriceCents } from '@/composables/useProductPricing';

const STORAGE_KEY = 'orange.cart.v1';

function load() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        return raw ? JSON.parse(raw) : [];
    } catch {
        return [];
    }
}

// Module-level state (not per-component) so the cart survives navigating
// between menu sections/pages within the same browser session.
const lines = reactive(load());

watch(
    lines,
    (value) => {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(value));
        } catch {
            // Storage unavailable (private browsing, quota) — cart just
            // won't persist across reloads, not worth surfacing an error.
        }
    },
    { deep: true },
);

// Same product + same selected modifiers = the same dedup group, so adding
// it again bumps the quantity instead of creating a second line. `id` is a
// separate, always-unique identifier for a specific line (used by
// updateQuantity/remove) — lines with notes never merge, so two lines can
// share a dedupKey while having different ids.
function dedupKey(menuItemId, modifierIds) {
    return `${menuItemId}:${[...modifierIds].sort((a, b) => a - b).join(',')}`;
}

let idCounter = 0;
function uniqueId() {
    idCounter += 1;

    return `line-${Date.now()}-${idCounter}`;
}

/**
 * Every orderable line on the live menu, by menu_item_id.
 *
 * Built from the payload the page was rendered with, so it is by
 * definition the current catalogue: current prices, current names, and
 * only the items still on sale.
 */
function indexMenus(menus) {
    const index = new Map();

    const walk = (category) => {
        for (const product of category.products ?? []) {
            const modifiers = new Map();

            for (const group of product.modifier_groups ?? []) {
                for (const modifier of group.modifiers ?? []) {
                    modifiers.set(modifier.id, modifier);
                }
            }

            index.set(product.menu_item_id, { product, modifiers });
        }

        for (const sub of category.subcategories ?? []) {
            walk(sub);
        }
    };

    for (const menu of menus ?? []) {
        for (const category of menu.categories ?? []) {
            walk(category);
        }
    }

    return index;
}

export function useCart() {
    /**
     * Re-checks a restored cart against the menu as it is now.
     *
     * The stored line carries the price it had when it was added, and this
     * cart lives in localStorage indefinitely — so a tablet left on the
     * menu page, or a customer returning the next day, kept showing
     * whatever the dish used to cost. The server never believed it (see
     * OrderItemWriter, which recomputes every line from the menu), which is
     * the right call for safety and exactly what made the bug so quiet: the
     * customer was shown one total and charged another, with nothing
     * anywhere saying the two had diverged.
     *
     * Reconciling on load is the fix. Dropped rather than repriced when the
     * dish is gone or one of its chosen extras no longer exists, because a
     * line whose composition cannot be honoured is not the thing the
     * customer chose.
     *
     * @returns {{removed: string[], repriced: string[]}} for telling them
     */
    function reconcile(menus) {
        const index = indexMenus(menus);
        const removed = [];
        const repriced = [];

        for (let i = lines.length - 1; i >= 0; i--) {
            const line = lines[i];
            const live = index.get(line.menu_item_id);

            if (!live) {
                removed.push(line.name);
                lines.splice(i, 1);
                continue;
            }

            const liveModifiers = line.modifiers.map((m) => live.modifiers.get(m.id));

            if (liveModifiers.some((m) => m === undefined)) {
                removed.push(line.name);
                lines.splice(i, 1);
                continue;
            }

            const livePrice = unitPriceCents(live.product, liveModifiers);

            if (livePrice !== line.unit_price_cents) {
                repriced.push(line.name);
                line.unit_price_cents = livePrice;
            }

            // The name and picture are shown straight from the cart, so they
            // go stale the same way the price did.
            line.name = live.product.name;
            line.image_url = live.product.image_url;
            line.modifiers = liveModifiers.map((m) => ({ id: m.id, modifier_group_id: m.modifier_group_id, name: m.name, price_cents: m.price_cents }));
        }

        return { removed, repriced };
    }

    function add(product, selectedModifiers, quantity = 1, notes = '') {
        const modifierIds = selectedModifiers.map((m) => m.id);
        const dedup = dedupKey(product.menu_item_id, modifierIds);

        if (!notes) {
            const existing = lines.find((line) => line.dedupKey === dedup && !line.notes);

            if (existing) {
                existing.quantity += quantity;
                return;
            }
        }

        lines.push({
            id: uniqueId(),
            dedupKey: dedup,
            product_id: product.id,
            menu_item_id: product.menu_item_id,
            name: product.name,
            image_url: product.image_url,
            // useProductPricing owns the rule — a size replaces the price,
            // an extra adds to it.
            unit_price_cents: unitPriceCents(product, selectedModifiers),
            modifiers: selectedModifiers.map((m) => ({ id: m.id, modifier_group_id: m.modifier_group_id, name: m.name, price_cents: m.price_cents })),
            quantity,
            notes,
        });
    }

    function updateQuantity(id, quantity) {
        const line = lines.find((l) => l.id === id);
        if (!line) return;

        if (quantity <= 0) {
            remove(id);
            return;
        }

        line.quantity = quantity;
    }

    function remove(id) {
        const index = lines.findIndex((l) => l.id === id);
        if (index !== -1) lines.splice(index, 1);
    }

    function clear() {
        lines.splice(0, lines.length);
    }

    const count = computed(() => lines.reduce((sum, l) => sum + l.quantity, 0));
    const totalCents = computed(() => lines.reduce((sum, l) => sum + l.unit_price_cents * l.quantity, 0));

    function toOrderPayload() {
        return lines.map((line) => ({
            menu_item_id: line.menu_item_id,
            quantity: line.quantity,
            notes: line.notes || null,
            modifier_ids: line.modifiers.map((m) => m.id),
        }));
    }

    return { lines, add, updateQuantity, remove, clear, count, totalCents, toOrderPayload, reconcile };
}
