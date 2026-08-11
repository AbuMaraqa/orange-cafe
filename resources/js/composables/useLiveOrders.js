import { onMounted, onUnmounted, reactive } from 'vue';

/**
 * Keeps a staff dashboard's order list in sync in real time over the
 * `orders` private Reverb channel — no polling. `order.placed` inserts a
 * full order; `order.status-updated` merges a partial update (status,
 * paid_at) into the matching order already in the list.
 */
export function useLiveOrders(initialOrders) {
    const orders = reactive(initialOrders.map((o) => ({ ...o })));

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
