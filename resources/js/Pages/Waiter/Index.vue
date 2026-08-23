<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import NavIcon from '@/Components/NavIcon.vue';
import OrderCard from '@/Components/Staff/OrderCard.vue';
import OrderAlertPopup from '@/Components/Staff/OrderAlertPopup.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SoundUnlockNotice from '@/Components/Staff/SoundUnlockNotice.vue';
import WaiterCallBanner from '@/Components/Staff/WaiterCallBanner.vue';
import WaiterCallPopup from '@/Components/Staff/WaiterCallPopup.vue';
import TableBillModal from '@/Components/Staff/TableBillModal.vue';
import TableFloorPlan from '@/Components/Staff/TableFloorPlan.vue';
import TableMovePicker from '@/Components/Staff/TableMovePicker.vue';
import { useOrderAlerts } from '@/composables/useOrderAlerts';
import { useStaffFloorPlan } from '@/composables/useStaffFloorPlan';
import { useWaiterCalls } from '@/composables/useWaiterCalls';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    tables: {
        type: Array,
        required: true,
    },
    untabledOrders: {
        type: Array,
        default: () => [],
    },
    waiterCalls: {
        type: Array,
        default: () => [],
    },
});

const { t } = useI18n();
const { calls, popupCall, dismissPopup } = useWaiterCalls(props.waiterCalls);
const { tables, untabledOrders } = useStaffFloorPlan(() => props.tables, () => props.untabledOrders);

// Every order this screen currently knows about, in one flat list: the
// alerts only care about ids, not about where an order is parked. Read
// from the props rather than the floor plan's own state on purpose —
// these are exactly what a reconnect re-read refreshes, which is how an
// order placed while the socket was away still gets announced.
const knownOrders = () => [
    ...props.tables.map((table) => table.active_order).filter(Boolean),
    ...props.untabledOrders,
];

const {
    alert: orderAlert,
    dismissAlert: dismissOrderAlert,
    pendingCount: queuedOrderAlerts,
} = useOrderAlerts(knownOrders);


const tableSearch = ref('');
const filteredTables = computed(() => {
    const term = tableSearch.value.trim().toLowerCase();
    return term ? tables.filter((t) => t.label.toLowerCase().includes(term)) : tables;
});

function acknowledgeCall(call) {
    if (popupCall.value?.id === call.id) dismissPopup();
    router.patch(route('waiter-calls.acknowledge', call.id), {}, { preserveScroll: true });
}

// The wait-time ticker and the urgency rule moved into WaiterCallBanner
// with the markup that used them.

const selected = ref(null);
const showMovePicker = ref(false);

// Reopened from ?table= — coming back from the order screen lands on
// the table that was open, not at the top of the room. Matched by id
// against the live list so a table that has since been deleted simply
// opens nothing rather than throwing.
onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    const wanted = Number(params.get('table'));

    if (!wanted) return;

    selected.value = tables.find((tbl) => tbl.id === wanted) ?? null;

    // The parameter is an instruction to carry out once, not a piece of
    // state. Left in the address bar it reopened this table's sheet on
    // every refresh — the waiter reloads to see the floor and gets one
    // table's bill in their face instead, again and again, with no way to
    // make it stop short of editing the URL.
    params.delete('table');

    const query = params.toString();

    window.history.replaceState({}, '', window.location.pathname + (query ? '?' + query : ''));
});

function openTable(table) {
    selected.value = table;
}

function confirmOrder(order) {
    router.post(route('orders.confirm', order.id), {}, { preserveScroll: true });
}

function printOrder(order) {
    router.post(route('orders.print', order.id), {}, { preserveScroll: true });
}

function onTableMoved() {
    showMovePicker.value = false;
    selected.value = null;
}
</script>

<template>
    <Head :title="$t('nav.waiter')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('waiter.title') }}</template>

        <div class="mb-4 flex justify-end">
            <Link :href="route('waiter.orders.create')">
                <PrimaryButton>{{ $t('waiter.new_order') }}</PrimaryButton>
            </Link>
        </div>

        <!-- Also on the till and the dashboard now — see WaiterCallBanner. -->
        <SoundUnlockNotice />
        <WaiterCallBanner :calls="calls" can-acknowledge @acknowledge="acknowledgeCall" />

        <input
            v-model="tableSearch"
            type="search"
            class="mb-4 block w-full max-w-xs rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-500 focus:ring-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"
            :placeholder="$t('waiter.search_table')"
        />

        <!-- Floor plan: same layout the admin arranged it in — tap a table
             for its details instead of showing everything at once. -->
        <p v-if="filteredTables.length === 0" class="text-sm text-gray-400">{{ $t('waiter.no_tables_found') }}</p>
        <TableFloorPlan v-else :tables="filteredTables" @select="openTable" />

        <div v-if="untabledOrders.length > 0" class="mt-8">
            <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $t('waiter.other_orders') }}</h3>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <OrderCard
                    v-for="order in untabledOrders"
                    :key="order.id"
                    :order="order"
                    show-invoice
                    show-print
                    show-confirm
                    @confirm="confirmOrder(order)"
                    @print="printOrder(order)"
                />
            </div>
        </div>

        <!-- The table's bill, the same sheet the till uses — the floor reads
             the order, confirms it and prints from here.

             No line editing on this screen, for anybody. It used to be
             offered to an administrator standing at the table, and the
             result was the edit controls appearing beside a confirmed order
             whose ticket was already in the kitchen — pressing one answered
             with a full-page 403. Correcting a bill is till work and lives
             on the till screen; the floor's job here is to accept the order
             and send it to be cooked. -->
        <TableBillModal
            v-if="selected"
            :table="selected"
            :can-edit="false"
            can-confirm
            can-move
            can-cancel
            can-print
            @close="selected = null"
            @move="showMovePicker = true"
        >
            <template #primary-action>
                <Link :href="route('waiter.orders.create', { table_id: selected.id })" class="block">
                    <PrimaryButton class="h-11 w-full justify-center text-sm">{{ $t('waiter.add_items') }}</PrimaryButton>
                </Link>
            </template>

            <template #empty-actions>
                <Link :href="route('waiter.orders.create', { table_id: selected.id })" class="block">
                    <PrimaryButton class="h-11 w-full justify-center text-sm">{{ $t('waiter.start_order') }}</PrimaryButton>
                </Link>
            </template>
        </TableBillModal>

        <TableMovePicker
            v-if="showMovePicker && selected?.active_order"
            :tables="tables"
            :current-table-id="selected.id"
            :order-id="selected.active_order.id"
            @close="showMovePicker = false"
            @moved="onTableMoved"
        />

        <!-- Waiter-call popup: pops up on top of everything the instant a
             new call arrives (plus the audio chime in useWaiterCalls), so
             it's hard to miss even if the waiter isn't looking at the
             banner list right now. The service's photo/color is the
             biggest visual (tells call types apart at a glance), but the
             table number badge on top of it is sized to be read just as
             fast — that's still the one fact that decides where to walk.
             When several calls arrive at once, useWaiterCalls queues them
             and shows the next one automatically once this one is
             dismissed/acknowledged; the counter below tells the waiter how
             many more are waiting. -->
        <!-- A table ordering is the other thing that interrupts this
             screen, and it is announced the same way a call is: same card,
             same chime family, one visual language. Rendered above the call
             popup because it dismisses itself; a call stays until somebody
             deals with it, so it is still there underneath. -->
        <OrderAlertPopup
            :alert="orderAlert"
            :pending-count="queuedOrderAlerts"
            @dismiss="dismissOrderAlert"
        />

        <!-- Extracted to a component so the till shows exactly the same
             card — see WaiterCallPopup. -->
        <WaiterCallPopup
            :call="popupCall"
            :remaining-count="Math.max(calls.length - 1, 0)"
            can-acknowledge
            @dismiss="dismissPopup"
            @acknowledge="acknowledgeCall"
        />
    </AuthenticatedLayout>

</template>
