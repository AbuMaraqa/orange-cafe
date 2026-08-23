import { onMounted, onUnmounted, reactive, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { joinOrdersChannel, onSocketReconnect } from '@/composables/useOrdersChannel';

/**
 * Orders still waiting for a waiter, on whatever screen they happen to be.
 *
 * The alert card (useOrderAlerts) is an interruption: it fires once, and it
 * is gone. That is right for "this just happened" and wrong for "this is
 * still true". A waiter takes an order at table 4 while table 9 orders from
 * its QR code, dismisses the card without really reading it, and table 9 is
 * now waiting on somebody who has no idea. Nothing on the screen they are
 * on — the new-order form, the till, the kitchen list — mentions it.
 *
 * So this is the standing count rather than the announcement: it appears
 * when there is something outstanding, it stays until somebody deals with
 * it, and it disappears on its own when they do. It is deliberately not
 * dismissible. A number you can dismiss is a number you will dismiss.
 *
 * Two sources, and both matter:
 *
 *  - `pending_orders`, shared by HandleInertiaRequests on every request, is
 *    the truth. It is what makes the count correct on a fresh page load,
 *    after a navigation, and after the socket has been away.
 *  - the `orders` channel keeps it current in between, so a table ordering
 *    while somebody stands still on one screen still shows up.
 *
 * The database is the source; the socket only saves a round trip.
 */
export function usePendingOrders() {
    const page = usePage();

    /** @type {Array<{id:number, reference:?string, table_id:?number, table_label:?string, created_at:?string}>} */
    const orders = reactive([]);

    function replaceAll(list) {
        orders.splice(0, orders.length, ...(list ?? []).map((order) => ({ ...order })));
    }

    function add(order) {
        if (!order || order.id == null) return;
        if (orders.some((o) => o.id === order.id)) return;

        orders.unshift({
            id: order.id,
            reference: order.reference ?? null,
            table_id: order.table_id ?? null,
            table_label: order.table_label ?? order.table_number ?? null,
            created_at: order.created_at ?? new Date().toISOString(),
        });
    }

    function remove(id) {
        const index = orders.findIndex((o) => o.id === id);

        if (index !== -1) orders.splice(index, 1);
    }

    // The shared prop arrives with every Inertia response, so this covers
    // the first render, every navigation, and every partial reload — which
    // is also what makes a stale count self-correct rather than persist.
    watch(
        () => page.props.pending_orders,
        (fresh) => replaceAll(fresh),
        // No `deep`: Inertia hands back a fresh array on every
        // response, so the identity change already fires this. Deep would
        // only add a recursive walk of the payload per response.
        { immediate: true }
    );

    let leaveChannel = null;
    let unbindReconnect = null;

    onMounted(async () => {
        // A user with no staff role cannot subscribe to this channel and has
        // nothing to show anyway — HandleInertiaRequests already sends them
        // an empty list. Asking would just be a rejected auth request on
        // every customer page.
        if (!page.props.auth?.is_staff) {
            return;
        }

        leaveChannel = await joinOrdersChannel({
            // Staff-placed orders are born confirmed and never belong here;
            // only a customer's own order waits for review.
            '.order.placed': (event) => {
                if (event.order?.status === 'pending') add(event.order);
            },
            // Confirmed, cancelled, or anything else: it is no longer
            // waiting on a waiter, so the badge stops claiming it is.
            '.order.status-updated': (event) => {
                if (event.order?.status !== 'pending') remove(event.order?.id);
            },
        });

        // Anything that happened while the socket was away is not in this
        // list and never will be — the channel replays nothing. Re-reading
        // the shared prop is what makes the count trustworthy again.
        unbindReconnect = onSocketReconnect(() => {
            router.reload({ only: ['pending_orders'] });
        });
    });

    onUnmounted(() => {
        leaveChannel?.();
        unbindReconnect?.();
    });

    return { orders };
}
