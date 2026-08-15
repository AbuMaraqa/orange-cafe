<script setup>
import { computed, ref } from 'vue';
import NavIcon from '@/Components/NavIcon.vue';
import OrderCard from '@/Components/Staff/OrderCard.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TableFloorPlan from '@/Components/Staff/TableFloorPlan.vue';
import TableMovePicker from '@/Components/Staff/TableMovePicker.vue';
import { nextStatus } from '@/constants/orderStatus';
import { useStaffFloorPlan } from '@/composables/useStaffFloorPlan';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
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
});

const { t } = useI18n();
const page = usePage();
const { tables, untabledOrders } = useStaffFloorPlan(() => props.tables, () => props.untabledOrders);

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

function advance(order) {
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
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
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

        <!-- Table popup: the active order rendered with the same OrderCard
             used for untabled orders, so payment/print/advance behave
             identically whether or not the order happens to sit on a table. -->
        <div v-if="selected" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/40 p-4" @click.self="selected = null">
            <div class="w-full max-w-sm">
                <div class="mb-2 flex items-center justify-between px-1">
                    <h3 class="text-lg font-semibold text-white drop-shadow">{{ selected.label }}</h3>
                    <button type="button" class="text-white/80 hover:text-white" @click="selected = null">
                        <NavIcon name="close" class="h-5 w-5" />
                    </button>
                </div>

                <OrderCard
                    v-if="selected.active_order"
                    :order="selected.active_order"
                    :next-status-label="statusLabel(selected.active_order.status)"
                    show-pay
                    show-invoice
                    show-print
                    show-cashier-print
                    show-item-prices
                    @advance="advance(selected.active_order)"
                    @pay="markPaid(selected.active_order, $event)"
                    @print="printOrder(selected.active_order)"
                    @print-cashier="printForCashier(selected.active_order)"
                />
                <div v-else class="rounded-lg border border-gray-200 bg-white p-4 text-center text-sm text-gray-500 dark:border-gray-800 dark:bg-gray-900">
                    {{ $t('tables.empty') }}
                </div>

                <SecondaryButton
                    v-if="selected.active_order && !selected.active_order.is_paid"
                    type="button"
                    class="mt-3 w-full justify-center"
                    @click="showMovePicker = true"
                >
                    {{ $t('tables.move_table') }}
                </SecondaryButton>
            </div>
        </div>

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
