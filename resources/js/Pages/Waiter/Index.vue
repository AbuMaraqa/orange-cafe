<script setup>
import { computed, ref } from 'vue';
import OrderCard from '@/Components/Staff/OrderCard.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { nextStatus } from '@/constants/orderStatus';
import { useOrderStatus } from '@/composables/useOrderStatus';
import { useWaiterCalls } from '@/composables/useWaiterCalls';
import { useWaiterFloorPlan } from '@/composables/useWaiterFloorPlan';
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
});

const { t } = useI18n();
const page = usePage();
const { statusBadgeClasses } = useOrderStatus();
const { calls } = useWaiterCalls(props.waiterCalls);
const { tables, untabledOrders } = useWaiterFloorPlan(() => props.tables, () => props.untabledOrders);

function formatPrice(cents) {
    return `${page.props.branding.currency_symbol}${(cents / 100).toFixed(2)}`;
}

function paidBadgeClasses(isPaid) {
    return isPaid
        ? 'bg-teal-100 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400'
        : 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400';
}

const tableSearch = ref('');
const filteredTables = computed(() => {
    const term = tableSearch.value.trim().toLowerCase();
    return term ? tables.filter((t) => t.label.toLowerCase().includes(term)) : tables;
});

function acknowledgeCall(call) {
    router.patch(route('waiter-calls.acknowledge', call.id), {}, { preserveScroll: true });
}

function statusClasses(table) {
    if (!table.active_order) {
        return 'border-gray-300 bg-white text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400';
    }
    if (table.active_order.is_paid) {
        return 'border-teal-500 bg-teal-50 text-teal-700 dark:bg-teal-900/30 dark:text-teal-300';
    }
    return 'border-amber-500 bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300';
}

const selected = ref(null);

function openTable(table) {
    selected.value = table;
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

function removeItem(order, item) {
    if (!confirm(`${item.product_name}: ${t('common.are_you_sure')}`)) return;
    router.delete(route('orders.items.destroy', [order.id, item.id]), { preserveScroll: true });
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

        <div v-if="calls.length > 0" class="mb-4 space-y-2">
            <div
                v-for="call in calls"
                :key="call.id"
                class="flex items-center justify-between rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 dark:border-amber-800 dark:bg-amber-900/30"
            >
                <p class="text-sm font-medium text-amber-800 dark:text-amber-300">
                    🔔 {{ call.table_label ? t('waiter.call_from_table', { table: call.table_label }) : t('waiter.call_generic') }}
                </p>
                <SecondaryButton type="button" @click="acknowledgeCall(call)">{{ t('waiter.acknowledge') }}</SecondaryButton>
            </div>
        </div>

        <input
            v-model="tableSearch"
            type="search"
            class="mb-4 block w-full max-w-xs rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-500 focus:ring-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"
            :placeholder="$t('waiter.search_table')"
        />

        <!-- Floor plan: every table at a glance, minimal text per tile —
             tap a table for its details instead of showing everything at once. -->
        <p v-if="filteredTables.length === 0" class="text-sm text-gray-400">{{ $t('waiter.no_tables_found') }}</p>
        <div v-else class="grid grid-cols-4 gap-3 sm:grid-cols-6 lg:grid-cols-8">
            <button
                v-for="table in filteredTables"
                :key="table.id"
                type="button"
                class="flex aspect-square flex-col items-center justify-center gap-0.5 rounded-lg border-2 text-sm font-semibold shadow-sm transition-transform hover:scale-105"
                :class="statusClasses(table)"
                @click="openTable(table)"
            >
                {{ table.label }}
                <span v-if="table.active_order" class="text-[11px] font-normal leading-tight">{{ table.active_order.items_count ?? table.active_order.items?.length }} 🍽</span>
                <span v-if="table.active_order" class="text-[9px] font-semibold leading-tight">
                    {{ table.active_order.is_paid ? $t('tables.paid') : $t('tables.unpaid') }}
                </span>
            </button>
        </div>

        <div v-if="untabledOrders.length > 0" class="mt-8">
            <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $t('waiter.other_orders') }}</h3>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <OrderCard
                    v-for="order in untabledOrders"
                    :key="order.id"
                    :order="order"
                    :next-status-label="statusLabel(order.status)"
                    show-invoice
                    removable-items
                    @advance="advance(order)"
                    @remove-item="removeItem(order, $event)"
                />
            </div>
        </div>

        <!-- Table popup -->
        <div v-if="selected" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/40 p-4" @click.self="selected = null">
            <div class="w-full max-w-sm rounded-lg bg-white p-5 dark:bg-gray-900">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ selected.label }}</h3>
                    <button type="button" class="text-gray-400 hover:text-gray-600" @click="selected = null">✕</button>
                </div>

                <template v-if="selected.active_order">
                    <div class="mb-4 flex items-center justify-between">
                        <span class="text-sm text-gray-500">{{ selected.active_order.reference }}</span>
                        <div class="flex items-center gap-1.5">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="statusBadgeClasses(selected.active_order.status_color)">
                                {{ t(`orders.statuses.${selected.active_order.status}`) }}
                            </span>
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="paidBadgeClasses(selected.active_order.is_paid)">
                                {{ selected.active_order.is_paid ? $t('tables.paid') : $t('tables.unpaid') }}
                            </span>
                        </div>
                    </div>

                    <ul class="mb-4 max-h-48 space-y-1 overflow-y-auto text-sm text-gray-700 dark:text-gray-300">
                        <li v-for="item in selected.active_order.items" :key="item.id" class="flex items-center justify-between gap-2">
                            <span><span class="font-medium">{{ item.quantity }}×</span> {{ item.product_name }}</span>
                            <button
                                v-if="selected.active_order.status === 'pending'"
                                type="button"
                                class="shrink-0 text-gray-300 hover:text-red-600"
                                :title="$t('common.delete')"
                                @click="removeItem(selected.active_order, item)"
                            >✕</button>
                        </li>
                    </ul>

                    <div class="mb-4 flex items-center justify-between border-t border-gray-100 pt-3 font-semibold text-gray-900 dark:border-gray-800 dark:text-gray-100">
                        <span>{{ $t('common.price') }}</span>
                        <span>{{ formatPrice(selected.active_order.total_cents) }}</span>
                    </div>

                    <div class="space-y-2">
                        <Link :href="route('waiter.orders.create', { table_id: selected.id })" class="block">
                            <PrimaryButton class="w-full justify-center">{{ $t('waiter.add_items') }}</PrimaryButton>
                        </Link>
                        <div class="flex gap-2">
                            <SecondaryButton
                                v-if="statusLabel(selected.active_order.status)"
                                class="flex-1 justify-center"
                                @click="advance(selected.active_order); selected = null"
                            >
                                {{ statusLabel(selected.active_order.status) }}
                            </SecondaryButton>
                            <a :href="route('orders.invoice', selected.active_order.id)" target="_blank" rel="noopener" class="flex-1">
                                <SecondaryButton class="w-full justify-center">{{ $t('staff.invoice') }}</SecondaryButton>
                            </a>
                        </div>
                    </div>
                </template>

                <template v-else>
                    <p class="mb-4 text-sm text-gray-500">{{ $t('waiter.empty_table') }}</p>
                    <Link :href="route('waiter.orders.create', { table_id: selected.id })" class="block">
                        <PrimaryButton class="w-full justify-center">{{ $t('waiter.start_order') }}</PrimaryButton>
                    </Link>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
