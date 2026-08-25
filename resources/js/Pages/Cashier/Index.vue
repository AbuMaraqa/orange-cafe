<script setup>
import { computed, onMounted, ref } from 'vue';
import OrderCard from '@/Components/Staff/OrderCard.vue';
import OrderAlertPopup from '@/Components/Staff/OrderAlertPopup.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import PrintAlert from '@/Components/Staff/PrintAlert.vue';
import TableBillModal from '@/Components/Staff/TableBillModal.vue';
import TableFloorPlan from '@/Components/Staff/TableFloorPlan.vue';
import TableMovePicker from '@/Components/Staff/TableMovePicker.vue';
import SoundUnlockNotice from '@/Components/Staff/SoundUnlockNotice.vue';
import WaiterCallBanner from '@/Components/Staff/WaiterCallBanner.vue';
import WaiterCallPopup from '@/Components/Staff/WaiterCallPopup.vue';
import { nextStatus } from '@/constants/orderStatus';
import { useOrderAlerts } from '@/composables/useOrderAlerts';
import { useStaffFloorPlan } from '@/composables/useStaffFloorPlan';
import { useWaiterCalls } from '@/composables/useWaiterCalls';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
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
    serviceCharges: {
        type: Array,
        default: () => [],
    },
});

const { t } = useI18n();
const page = usePage();
const isAdmin = computed(() => (page.props.auth.roles ?? []).includes('admin'));
const { tables, untabledOrders } = useStaffFloorPlan(() => props.tables, () => props.untabledOrders);

// The till needs to hear a table order for the same reason the floor does:
// it is the screen somebody is usually standing at, and an order that
// nobody notices is an order nobody takes to the table. See the waiter
// screen for why this reads from props rather than the floor plan state.
const knownOrders = () => [
    ...props.tables.map((table) => table.active_order).filter(Boolean),
    ...props.untabledOrders,
];

const {
    alert: orderAlert,
    dismissAlert: dismissOrderAlert,
    pendingCount: queuedOrderAlerts,
} = useOrderAlerts(knownOrders);


// The service chime, on the till as well as the floor. The cashier is
// often the person standing closest to the tables, and on a quiet shift
// the waiter board may not be open anywhere — in which case a customer
// pressing the button on their table produced a sound in an empty room.
//
// The popup stays on the waiter board: answering a call is their job, and
// a full-screen sheet arriving mid-payment is one the cashier would
// dismiss without reading. Here it is the banner and the chime.
// The popup as well as the banner. The till used to get only the quiet
// list down the page, which meant a table asking for something reached the
// one member of staff most likely to be standing still — and least likely
// to be scrolling — as a line of text they had to notice on their own.
const { calls, popupCall, dismissPopup } = useWaiterCalls(props.waiterCalls);

function acknowledgeCall(call) {
    if (popupCall.value?.id === call.id) dismissPopup();

    router.patch(route('waiter-calls.acknowledge', call.id), {}, { preserveScroll: true });
}

const tableSearch = ref('');
const filteredTables = computed(() => {
    const term = tableSearch.value.trim().toLowerCase();
    return term ? tables.filter((tbl) => tbl.label.toLowerCase().includes(term)) : tables;
});

const outstandingCents = computed(() => {
    const tabled = tables
        .filter((tbl) => tbl.active_order && !tbl.active_order.is_paid)
        .reduce((sum, tbl) => sum + tbl.active_order.total_cents, 0);
    const untabled = untabledOrders
        .filter((o) => !o.is_paid)
        .reduce((sum, o) => sum + o.total_cents, 0);

    return tabled + untabled;
});

function formatPrice(cents) {
    return `${page.props.branding.currency_symbol}${(cents / 100).toFixed(2)}`;
}

function statusLabel(status) {
    const next = nextStatus(status);
    return next ? t(`orders.statuses.${next}`) : null;
}

/**
 * Accepting a pending order is not the same request as moving an accepted
 * one along, even though it is the same button.
 *
 * 'pending' -> 'confirmed' is the moment the order becomes real: the kitchen
 * ticket is queued and the round is folded into the table's running bill.
 * Both of those live in orders.confirm. orders.status only writes the word,
 * so reaching 'confirmed' through it left the till showing an accepted order
 * that the kitchen had never been told about and that was still sitting on a
 * ticket of its own — the split bill that lets food go out unpaid.
 *
 * The waiter screen always posted to orders.confirm; the till was advancing
 * the status instead, which is why the same button printed there and not
 * here. Every step after this one is a plain status move and stays that way.
 */
function advance(order) {
    if (order.status === 'pending') {
        router.post(route('orders.confirm', order.id), {}, { preserveScroll: true });

        return;
    }

    const next = nextStatus(order.status);
    if (!next) return;

    router.patch(route('orders.status', order.id), { status: next }, { preserveScroll: true });
}

function markPaid(order, amountPaidCents) {
    router.patch(route('orders.pay', order.id), { amount_paid_cents: amountPaidCents }, { preserveScroll: true });
}

function printOrder(order) {
    router.post(route('orders.print', order.id), {}, { preserveScroll: true });
}

function printForCashier(order) {
    router.post(route('orders.print-cashier', order.id), {}, { preserveScroll: true });
}

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

function onTableMoved() {
    showMovePicker.value = false;
    selected.value = null;
}
</script>

<template>
    <Head :title="$t('nav.cashier')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('cashier.title') }}</template>

        <!-- The one thing a cashier must be told about printing, and the
             only place they are told it. Everything technical stays on the
             printers screen. -->
        <PrintAlert :details-route="page.props.auth.roles.includes('admin') ? route('admin.print-jobs.index') : null" />

        <SoundUnlockNotice />

        <WaiterCallBanner :calls="calls" can-acknowledge @acknowledge="acknowledgeCall" />

        <WaiterCallPopup
            :call="popupCall"
            :remaining-count="Math.max(calls.length - 1, 0)"
            can-acknowledge
            @dismiss="dismissPopup"
            @acknowledge="acknowledgeCall"
        />

        <OrderAlertPopup
            :alert="orderAlert"
            :pending-count="queuedOrderAlerts"
            @dismiss="dismissOrderAlert"
        />


        <div class="mb-4 flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <span class="text-sm text-gray-500">{{ t('tables.title') }}</span>
            <span class="text-sm font-semibold text-amber-600">{{ t('cashier.outstanding') }}: {{ formatPrice(outstandingCents) }}</span>
        </div>

        <input
            v-model="tableSearch"
            type="search"
            class="mb-4 block w-full max-w-xs rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-500 focus:ring-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"
            :placeholder="$t('waiter.search_table')"
        />

        <p v-if="filteredTables.length === 0" class="text-sm text-gray-400">{{ $t('waiter.no_tables_found') }}</p>
        <TableFloorPlan v-else :tables="filteredTables" @select="openTable" />

        <div v-if="untabledOrders.length > 0" class="mt-8">
            <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $t('waiter.other_orders') }}</h3>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <OrderCard
                    v-for="order in untabledOrders"
                    :key="order.id"
                    :order="order"
                    :next-status-label="statusLabel(order.status)"
                    show-pay
                    show-invoice
                    show-print
                    show-cashier-print
                    show-item-prices
                    collapsible
                    @advance="advance(order)"
                    @pay="markPaid(order, $event)"
                    @print="printOrder(order)"
                    @print-cashier="printForCashier(order)"
                />
            </div>
        </div>

        <!-- The table's bill. It carries its own pay/print/advance and, for
             an administrator, line editing — the till is where a discount
             gets agreed, so that is where it has to be changeable. -->
        <TableBillModal
            v-if="selected"
            :table="selected"
            :can-edit="isAdmin"
            :service-charges="serviceCharges"
            can-add-service-charge
            can-pay
            can-advance
            can-move
            can-cancel
            can-print
            can-print-cashier
            can-invoice
            @close="selected = null"
            @move="showMovePicker = true"
        >
            <!-- The till opens tables too. A walk-in who orders and pays at
                 the counter, or a customer already sitting down that nobody
                 has got to yet, should not have to wait for a waiter to be
                 free before anything reaches the kitchen. Same screen the
                 waiter uses — the order is born confirmed and prints
                 immediately either way (see config/orders.php). -->
            <template #primary-action>
                <Link :href="route('waiter.orders.create', { table_id: selected.id })" class="block">
                    <PrimaryButton class="h-11 w-full justify-center text-sm">{{ $t('waiter.add_items') }}</PrimaryButton>
                </Link>
            </template>

            <template #empty-actions>
                <Link :href="route('waiter.orders.create', { table_id: selected.id })" class="block">
                    <PrimaryButton class="h-11 w-full justify-center text-sm">{{ $t('tables.open_table') }}</PrimaryButton>
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
    </AuthenticatedLayout>
</template>
