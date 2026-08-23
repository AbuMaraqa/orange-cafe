import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { joinOrdersChannel } from '@/composables/useOrdersChannel';
import { playNotification, prepareAlertSound } from '@/composables/useAlertSound';

/**
 * Announces a table's order to whoever is watching the screen.
 *
 * The floor plan and the order lists already keep themselves current over
 * the socket, but they do it *quietly*: a tile changes colour somewhere on
 * a screen nobody is looking at. A waiter carrying plates does not notice a
 * tile. So an order placed from a table arrived correctly and still went
 * unserved, which from the floor is indistinguishable from it never
 * arriving at all.
 *
 * This is the part that makes it impossible to miss — one sound, one card
 * in the middle of the screen — and it is deliberately separate from the
 * state composables: they own what the screen *shows*, this owns what it
 * *announces*. Both read the same channel through the same reference
 * counted subscription (useOrdersChannel), so adding it costs no extra
 * connection.
 *
 * ## Once per order, whatever happens
 *
 * Every alert is keyed on the order id, and an id is only ever announced
 * once. That single rule covers all the ways the same order can reach a
 * screen more than once:
 *
 *  - the socket redelivering an event,
 *  - the same order arriving over the socket *and* in the page props,
 *  - a reconnect re-read handing back orders that were already on screen,
 *  - the props watcher firing on an unrelated change.
 *
 * ## Orders missed while the socket was away
 *
 * The socket replays nothing on reconnect, so an order placed during an
 * outage would otherwise be silently absent. useLiveOrders and
 * useStaffFloorPlan re-read their props when the socket returns; this
 * watches the result, and anything whose id it has not announced yet gets
 * announced then. Late is the correct behaviour — the table is still
 * waiting.
 *
 * @param {() => Array} getOrders reactive getter for every order currently
 *                                known to the screen, in any shape that
 *                                carries `id` (e.g. `() => props.orders`).
 */
export function useOrderAlerts(getOrders) {
    /** The card currently on screen, or null. */
    const alert = ref(null);

    /** Announced ids. Never announced twice, however the order arrives. */
    const announced = new Set();

    /** Waiting their turn, oldest first. */
    const queue = ref([]);

    let dismissTimer = null;

    // Long enough to read across a room, short enough that a screen nobody
    // is standing at does not end the evening buried under cards.
    const AUTO_DISMISS_MS = 12000;

    const pendingCount = computed(() => queue.value.length);

    function showNext() {
        if (alert.value || queue.value.length === 0) {
            return;
        }

        alert.value = queue.value.shift();

        clearTimeout(dismissTimer);

        // The order itself is on the floor plan and in the list either
        // way, which is the record that matters.
        dismissTimer = setTimeout(dismissAlert, AUTO_DISMISS_MS);
    }

    function dismissAlert() {
        clearTimeout(dismissTimer);
        alert.value = null;
        showNext();
    }

    /**
     * @param {object} order    an order-shaped object carrying at least `id`
     * @param {boolean} addition items added to a ticket the table already had
     */
    function announce(order, addition) {
        if (!order || order.id == null || announced.has(order.id)) {
            return;
        }

        announced.add(order.id);

        queue.value.push({
            id: order.id,
            reference: order.reference ?? null,
            tableLabel: order.table_label ?? order.table_number ?? null,
            itemsCount: order.items_count ?? order.items?.length ?? null,
            totalCents: order.total_cents ?? null,
            isAddition: Boolean(addition),
            at: new Date(),
        });

        playNotification(addition ? 'addition' : 'order');

        showNext();
    }

    // Whatever is already on the screen when it opens is not news. Seeding
    // silently is what stops a kitchen screen from playing eleven chimes
    // the moment somebody refreshes it during service.
    function seedSilently() {
        (getOrders() ?? []).forEach((order) => {
            if (order?.id != null) announced.add(order.id);
        });
    }

    // Props change for many reasons — an Inertia visit, the acting user's
    // own action, the reconnect re-read. Only ids never announced before
    // produce an alert, so the only case that actually reaches announce()
    // is an order this screen has genuinely not seen.
    watch(getOrders, (fresh) => {
        (fresh ?? []).forEach((order) => announce(order, false));
    });

    let leaveChannel = null;

    onMounted(async () => {
        // Ask for the browser's permission to make a sound before the first
        // order arrives, not on it.
        prepareAlertSound();

        seedSilently();

        leaveChannel = await joinOrdersChannel({
            '.order.placed': (event) => announce(event.order, event.is_addition),
        });
    });

    onUnmounted(() => {
        clearTimeout(dismissTimer);
        leaveChannel?.();
    });

    return { alert, dismissAlert, pendingCount };
}
