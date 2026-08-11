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

function lineKey(productId, modifierIds) {
    return `${productId}:${[...modifierIds].sort((a, b) => a - b).join(',')}`;
}

export function useCart() {
    function add(product, selectedModifiers, quantity = 1, notes = '') {
        const modifierIds = selectedModifiers.map((m) => m.id);
        const key = lineKey(product.id, modifierIds);
        const existing = lines.find((line) => line.key === key && !notes);

        if (existing && !notes) {
            existing.quantity += quantity;
            return;
        }

        lines.push({
            key: `${key}:${Date.now()}`,
            product_id: product.id,
            name: product.name,
            image_url: product.image_url,
            unit_price_cents: product.price_cents + selectedModifiers.reduce((sum, m) => sum + m.price_cents, 0),
            modifiers: selectedModifiers.map((m) => ({ id: m.id, name: m.name, price_cents: m.price_cents })),
            quantity,
            notes,
        });
    }

    function updateQuantity(key, quantity) {
        const line = lines.find((l) => l.key === key);
        if (!line) return;

        if (quantity <= 0) {
            remove(key);
            return;
        }

        line.quantity = quantity;
    }

    function remove(key) {
        const index = lines.findIndex((l) => l.key === key);
        if (index !== -1) lines.splice(index, 1);
    }

    function clear() {
        lines.splice(0, lines.length);
    }

    const count = computed(() => lines.reduce((sum, l) => sum + l.quantity, 0));
    const totalCents = computed(() => lines.reduce((sum, l) => sum + l.unit_price_cents * l.quantity, 0));

    function toOrderPayload() {
        return lines.map((line) => ({
            product_id: line.product_id,
            quantity: line.quantity,
            notes: line.notes || null,
            modifier_ids: line.modifiers.map((m) => m.id),
        }));
    }

    return { lines, add, updateQuantity, remove, clear, count, totalCents, toOrderPayload };
}
