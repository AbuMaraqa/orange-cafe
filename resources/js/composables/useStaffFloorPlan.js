import { onMounted, onUnmounted, reactive, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { joinOrdersChannel, onSocketReconnect } from '@/composables/useOrdersChannel';

const CLOSED_STATUSES = ['completed', 'cancelled'];

/**
 * Keeps a staff floor plan's table occupancy — and the "other orders" list
 * for orders with no table — in sync in real time over the same `orders`
 * private Reverb channel every staff dashboard subscribes to (see
 * useLiveOrders), AND re-synced from fresh Inertia props after the acting
 * user's own actions (e.g. removing an item) — broadcasts use `toOthers()`
 * so the actor never gets their own change over the socket, only through
 * the page's own response. Existing table/order objects are patched in
 * place rather than replaced so a currently-open popup (which holds a
 * direct reference into this state) keeps reflecting it live. Shared by
 * the waiter and cashier floor plans — table occupancy works identically
 * for both.
 *
 * @param {() => Array} getTables reactive getter, e.g. `() => props.tables`
 * @param {() => Array} getUntabledOrders reactive getter, e.g. `() => props.untabledOrders`
 */
export function useStaffFloorPlan(getTables, getUntabledOrders) {
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

        // A round that was waiting for a person. Accepting it folds it into
        // the table's bill and closes it, so it leaves this list; anything
        // else about it (an extra item, a cancellation) is patched in place.
        //
        // Checked before the bill, because a waiting round is not
        // active_order and findTableWithOrder would miss it entirely — the
        // round would then be treated as brand new and land a second copy of
        // itself on the table.
        const tableAwaitingIt = tables.find((t) => t.pending_rounds?.some((r) => r.id === patch.id));

        if (tableAwaitingIt) {
            if (CLOSED_STATUSES.includes(patch.status) || !isAwaitingReview(patch)) {
                removePendingRound(tableAwaitingIt, patch.id);
            } else {
                addPendingRound(tableAwaitingIt, patch);
            }

            return;
        }

        const owningTable = findTableWithOrder(patch.id);

        if (owningTable) {
            if (CLOSED_STATUSES.includes(patch.status)) {
                owningTable.active_order = null;
                return;
            }

            // Moved to a different table (or off the floor plan entirely) —
            // e.g. StaffOrderController::moveTable — so relocate it instead
            // of patching it in place, otherwise every OTHER connected
            // staff screen would keep showing it stuck on its old table
            // until they reload.
            if ('table_id' in patch && patch.table_id !== owningTable.id) {
                const movedOrder = { ...owningTable.active_order, ...patch };
                owningTable.active_order = null;
                relocate(movedOrder, patch.table_id);
                return;
            }

            Object.assign(owningTable.active_order, patch);
            // The bill's total is half of "what has this table asked for".
            recountRequested(owningTable);

            return;
        }

        const untabledIndex = untabledOrders.findIndex((o) => o.id === patch.id);

        if (untabledIndex !== -1) {
            if (CLOSED_STATUSES.includes(patch.status)) {
                untabledOrders.splice(untabledIndex, 1);
                return;
            }

            if ('table_id' in patch && patch.table_id) {
                const movedOrder = { ...untabledOrders[untabledIndex], ...patch };
                untabledOrders.splice(untabledIndex, 1);
                relocate(movedOrder, patch.table_id);
                return;
            }

            Object.assign(untabledOrders[untabledIndex], patch);
            return;
        }

        if (!isNew || CLOSED_STATUSES.includes(patch.status)) return;

        relocate(patch, patch.table_id);
    }

    function relocate(order, tableId) {
        const targetTable = tableId ? tables.find((t) => t.id === tableId) : null;

        if (!targetTable) {
            untabledOrders.unshift(order);

            return;
        }

        // A round arriving on a table that is already running a bill goes
        // *beside* it, never over it.
        //
        // This used to assign straight onto active_order, and a customer
        // ordering a second time made their table's bill vanish from every
        // floor screen mid-service: the tile dropped from 100.00 to the
        // 20.00 of the new round, and the sheet showed one item where there
        // had been four. Nothing was lost in the database — the server sends
        // the bill and the waiting rounds separately — but the screen had
        // overwritten one with the other, so a waiter asked what the table
        // had ordered could only see the last thing they said.
        //
        // A round is not the bill until somebody accepts it, at which point
        // the server folds it in and the merge arrives as a normal update.
        if (targetTable.active_order && isAwaitingReview(order) && !isAwaitingReview(targetTable.active_order)) {
            addPendingRound(targetTable, order);

            return;
        }

        targetTable.active_order = order;
    }

    function isAwaitingReview(order) {
        return order?.status === 'pending';
    }

    /** @param {object} table @param {object} order */
    function addPendingRound(table, order) {
        table.pending_rounds ??= [];

        const existing = table.pending_rounds.find((r) => r.id === order.id);

        if (existing) {
            Object.assign(existing, order);
        } else {
            table.pending_rounds.push({ ...order });
        }

        recountRequested(table);
    }

    function removePendingRound(table, orderId) {
        if (!table.pending_rounds?.length) return false;

        const index = table.pending_rounds.findIndex((r) => r.id === orderId);

        if (index === -1) return false;

        table.pending_rounds.splice(index, 1);
        recountRequested(table);

        return true;
    }

    /**
     * What the table has asked for in total, kept in step with the rounds.
     *
     * The server computes the same figure; this is the between-requests
     * version, so the number does not sit still while the list beneath it
     * grows.
     */
    function recountRequested(table) {
        table.requested_total_cents = (table.active_order?.total_cents ?? 0)
            + (table.pending_rounds ?? []).reduce((sum, r) => sum + (r.total_cents ?? 0), 0);
    }

    // Reference counted, so unmounting this screen cannot unsubscribe the
    // waiter-call alerts running beside it — see useOrdersChannel.
    let leaveChannel = null;
    let unbindReconnect = null;

    onMounted(async () => {
        leaveChannel = await joinOrdersChannel({
            '.order.placed': (event) => upsert(event.order, true),
            '.order.status-updated': (event) => upsert(event.order, false),
        });

        // Re-read the floor whenever the socket comes back, so anything
        // missed while it was away corrects itself. `only` keeps it to this
        // screen's own props rather than a full page load.
        unbindReconnect = onSocketReconnect(() => {
            router.reload({ only: ['tables', 'untabledOrders'] });
        });
    });

    onUnmounted(() => {
        leaveChannel?.();
        unbindReconnect?.();
    });

    return { tables, untabledOrders };
}
