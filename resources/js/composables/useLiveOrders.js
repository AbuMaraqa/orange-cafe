import { onMounted, onUnmounted, reactive, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { joinOrdersChannel, onSocketReconnect } from '@/composables/useOrdersChannel';

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

    // Reference counted — see useOrdersChannel. Leaving the channel
    // outright would deafen anything else listening on this same page.
    let leaveChannel = null;
    let unbindReconnect = null;

    onMounted(async () => {
        leaveChannel = await joinOrdersChannel({
            '.order.placed': (event) => upsert(event.order, true),
            '.order.status-updated': (event) => upsert(event.order, false),
        });

        // Anything broadcast while the socket was away is simply gone — the
        // channel replays nothing on reconnect. Without this the kitchen
        // screen silently misses every order placed during the outage and
        // never recovers, because the correction *was* the missed event: it
        // stays wrong until somebody reloads the page, and nothing on screen
        // suggests anything is missing.
        //
        // The floor plan has always done this (useStaffFloorPlan); the order
        // lists — kitchen and the admin dashboard — never did.
        //
        // `only` keeps it to this screen's own props rather than a full page
        // load, and the props watcher above reconciles the result: existing
        // orders are patched in place, ones that have since disappeared are
        // dropped, and anything missed arrives as new.
        unbindReconnect = onSocketReconnect(() => {
            router.reload({ only: ['orders'] });
        });
    });

    onUnmounted(() => {
        leaveChannel?.();
        unbindReconnect?.();
    });

    return { orders };
}
