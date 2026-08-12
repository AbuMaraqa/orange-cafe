import { onMounted, onUnmounted, reactive, watch } from 'vue';

const CLOSED_STATUSES = ['completed', 'cancelled'];

/**
 * Keeps the waiter floor plan's table occupancy — and the "other orders"
 * list for orders with no table — in sync in real time over the same
 * `orders` private Reverb channel every staff dashboard subscribes to
 * (see useLiveOrders), AND re-synced from fresh Inertia props after the
 * acting waiter's own actions (e.g. removing an item) — broadcasts use
 * `toOthers()` so the actor never gets their own change over the socket,
 * only through the page's own response. Existing table/order objects are
 * patched in place rather than replaced so a currently-open popup (which
 * holds a direct reference into this state) keeps reflecting it live.
 *
 * @param {() => Array} getTables reactive getter, e.g. `() => props.tables`
 * @param {() => Array} getUntabledOrders reactive getter, e.g. `() => props.untabledOrders`
 */
export function useWaiterFloorPlan(getTables, getUntabledOrders) {
    const tables = reactive(getTables().map((t) => ({ ...t })));
    const untabledOrders = reactive(getUntabledOrders().map((o) => ({ ...o })));

    function mergeList(list, incoming, keyFn = (x) => x.id) {
        incoming.forEach((item) => {
            const existing = list.find((x) => keyFn(x) === keyFn(item));
            if (existing) {
                Object.assign(existing, item);
            } else {
                list.push({ ...item });
            }
        });
        for (let i = list.length - 1; i >= 0; i -= 1) {
            if (!incoming.some((item) => keyFn(item) === keyFn(list[i]))) list.splice(i, 1);
        }
    }

    watch(getTables, (newTables) => {
        mergeList(tables, newTables);
        // Preserve object identity on nested active_order too (same reason).
        newTables.forEach((incoming) => {
            const table = tables.find((t) => t.id === incoming.id);
            if (!table) return;
            if (!incoming.active_order) {
                table.active_order = null;
            } else if (table.active_order) {
                Object.assign(table.active_order, incoming.active_order);
            } else {
                table.active_order = { ...incoming.active_order };
            }
        });
    });

    watch(getUntabledOrders, (newOrders) => mergeList(untabledOrders, newOrders));

    function findTableWithOrder(orderId) {
        return tables.find((t) => t.active_order?.id === orderId);
    }

    function upsert(rawPatch, isNew) {
        const patch = { ...rawPatch };

        // The broadcast carries `paid_at` (when); the floor plan's table
        // color/badge reads the derived `is_paid` boolean instead.
        if ('paid_at' in patch) {
            patch.is_paid = patch.paid_at !== null;
            delete patch.paid_at;
        }

        const owningTable = findTableWithOrder(patch.id);

        if (owningTable) {
            if (CLOSED_STATUSES.includes(patch.status)) {
                owningTable.active_order = null;
            } else {
                Object.assign(owningTable.active_order, patch);
            }
            return;
        }

        const untabledIndex = untabledOrders.findIndex((o) => o.id === patch.id);

        if (untabledIndex !== -1) {
            if (CLOSED_STATUSES.includes(patch.status)) {
                untabledOrders.splice(untabledIndex, 1);
            } else {
                Object.assign(untabledOrders[untabledIndex], patch);
            }
            return;
        }

        if (!isNew || CLOSED_STATUSES.includes(patch.status)) return;

        const targetTable = patch.table_id ? tables.find((t) => t.id === patch.table_id) : null;

        if (targetTable) {
            targetTable.active_order = patch;
        } else {
            untabledOrders.unshift(patch);
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

    return { tables, untabledOrders };
}
