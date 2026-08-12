import { computed, reactive, watch } from 'vue';

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

export function useCart() {
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
            unit_price_cents: product.price_cents + selectedModifiers.reduce((sum, m) => sum + m.price_cents, 0),
            modifiers: selectedModifiers.map((m) => ({ id: m.id, name: m.name, price_cents: m.price_cents })),
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

    return { lines, add, updateQuantity, remove, clear, count, totalCents, toOrderPayload };
}
