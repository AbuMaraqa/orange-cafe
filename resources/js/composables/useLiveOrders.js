import { onMounted, onUnmounted, reactive, watch } from 'vue';

/**
 * Keeps a staff dashboard's order list in sync in real time over the
 * `orders` private Reverb channel — no polling. `order.placed` inserts a
 * full order; `order.status-updated` merges a partial update (status,
 * paid_at, items, total_cents) into the matching order already in the
 * list. Also re-synced from fresh Inertia props (existing entries patched
 * in place, not replaced) so the acting user's own actions — broadcast
 * with `toOthers()` and therefore never echoed back to their own socket —
 * still show up once the page's own response comes back.
 *
 * @param {() => Array} getOrders reactive getter, e.g. `() => props.orders`
 */
export function useLiveOrders(getOrders) {
    const orders = reactive(getOrders().map((o) => ({ ...o })));

    function upsert(data, isNew) {
        const index = orders.findIndex((o) => o.id === data.id);
        const patch = { ...data };

        if ('paid_at' in patch) {
            patch.is_paid = patch.paid_at !== null;
            delete patch.paid_at;
        }

        if (index !== -1) {
            Object.assign(orders[index], patch);
        } else if (isNew) {
            orders.unshift(patch);
        }
    }

    watch(getOrders, (fresh) => {
        fresh.forEach((incoming) => upsert(incoming, true));
        for (let i = orders.length - 1; i >= 0; i -= 1) {
            if (!fresh.some((o) => o.id === orders[i].id)) orders.splice(i, 1);
        }
    });

    let channel = null;

    onMounted(async () => {
        if (!window.Echo) {
            await import('../echo');
        }

        channel = window.Echo.private('orders');
        channel.listen('.order.placed', (event) => upsert(event.order, true));
        channel.listen('.order.status-updated', (event) => upsert(event.order, false));
    });

    onUnmounted(() => {
        window.Echo?.leave('orders');
    });

    return { orders };
}
