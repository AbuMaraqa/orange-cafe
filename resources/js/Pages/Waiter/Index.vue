<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import NavIcon from '@/Components/NavIcon.vue';
import OrderCard from '@/Components/Staff/OrderCard.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TableFloorPlan from '@/Components/Staff/TableFloorPlan.vue';
import TableMovePicker from '@/Components/Staff/TableMovePicker.vue';
import { useOrderStatus } from '@/composables/useOrderStatus';
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
});

const { t } = useI18n();
const page = usePage();
const { statusBadgeClasses } = useOrderStatus();
const { calls, popupCall, dismissPopup } = useWaiterCalls(props.waiterCalls);
const { tables, untabledOrders } = useStaffFloorPlan(() => props.tables, () => props.untabledOrders);

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
    if (popupCall.value?.id === call.id) dismissPopup();
    router.patch(route('waiter-calls.acknowledge', call.id), {}, { preserveScroll: true });
}

// Ticks every 15s purely to force callWaitMinutes() to recompute — a call
// that's been sitting for a while should visibly age without a reload, so
// the waiter can tell at a glance which table has waited longest.
const nowTick = ref(Date.now());
let tickInterval = null;
onMounted(() => {
    tickInterval = setInterval(() => (nowTick.value = Date.now()), 15000);
});
onUnmounted(() => clearInterval(tickInterval));

function callWaitMinutes(call) {
    void nowTick.value;
    return Math.max(0, Math.round((Date.now() - new Date(call.created_at).getTime()) / 60000));
}

function callLabel(call) {
    if (call.service_name) {
        return call.table_label
            ? t('waiter.call_from_table_service', { table: call.table_label, service: call.service_name })
            : t('waiter.call_generic_service', { service: call.service_name });
    }

    return call.table_label ? t('waiter.call_from_table', { table: call.table_label }) : t('waiter.call_generic');
}

// calls is already oldest-first (see useWaiterCalls), so the first entry
// has been waiting longest — flag it once it's been over 2 minutes so the
// waiter's eye goes straight to the most urgent table in a busy moment.
const urgentCallId = computed(() => {
    if (calls.length === 0) return null;
    return callWaitMinutes(calls[0]) >= 2 ? calls[0].id : null;
});

const selected = ref(null);
const showMovePicker = ref(false);

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

        <div v-if="calls.length > 0" class="mb-4 space-y-2">
            <div
                v-for="call in calls"
                :key="call.id"
                class="flex items-center justify-between rounded-lg border px-4 py-3 transition-colors"
                :class="call.id === urgentCallId
                    ? 'border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-900/30'
                    : 'border-amber-300 bg-amber-50 dark:border-amber-800 dark:bg-amber-900/30'"
            >
                <div class="flex items-center gap-2">
                    <span
                        v-if="call.service_icon || call.service_image_url"
                        class="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full text-lg leading-none text-white"
                        :style="{ backgroundColor: call.service_color }"
                    >
                        <img v-if="call.service_image_url" :src="call.service_image_url" class="h-full w-full object-cover" />
                        <template v-else>{{ call.service_icon }}</template>
                    </span>
                    <NavIcon
                        v-else
                        name="bell"
                        class="h-5 w-5"
                        :class="call.id === urgentCallId ? 'animate-pulse text-red-500' : 'text-amber-500'"
                    />
                    <p class="text-sm font-medium" :class="call.id === urgentCallId ? 'text-red-800 dark:text-red-300' : 'text-amber-800 dark:text-amber-300'">
                        {{ callLabel(call) }}
                        <span class="font-normal opacity-75">
                            · {{ callWaitMinutes(call) < 1 ? t('staff.just_now') : t('staff.minutes_ago', { minutes: callWaitMinutes(call) }) }}
                        </span>
                    </p>
                </div>
                <SecondaryButton type="button" @click="acknowledgeCall(call)">{{ t('waiter.acknowledge') }}</SecondaryButton>
            </div>
        </div>

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
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
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

        <!-- Table popup -->
        <div v-if="selected" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/40 p-4" @click.self="selected = null">
            <div class="w-full max-w-sm rounded-lg bg-white p-5 dark:bg-gray-900">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ selected.label }}</h3>
                    <button type="button" class="text-gray-400 hover:text-gray-600" @click="selected = null">
                        <NavIcon name="close" class="h-4 w-4" />
                    </button>
                </div>

                <template v-if="selected.active_order">
                    <div class="mb-4 flex items-center justify-between">
                        <span class="text-sm text-gray-500">
                            {{ selected.active_order.reference }}
                            <span v-if="selected.active_order.customer_name" class="font-medium text-gray-800 dark:text-gray-200">· {{ selected.active_order.customer_name }}</span>
                        </span>
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
                        <li v-for="item in selected.active_order.items" :key="item.id">
                            <span class="font-medium">{{ item.quantity }}×</span> {{ item.product_name }}
                            <div v-if="item.notes" class="ms-4 text-xs italic text-amber-600 dark:text-amber-500">{{ item.notes }}</div>
                        </li>
                    </ul>
                    <p v-if="selected.active_order.notes" class="mb-4 -mt-2 text-xs italic text-amber-600 dark:text-amber-500">{{ selected.active_order.notes }}</p>

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
                                v-if="selected.active_order.status === 'pending'"
                                class="flex-1 justify-center"
                                @click="confirmOrder(selected.active_order); selected = null"
                            >
                                {{ $t('staff.confirm_order') }}
                            </SecondaryButton>
                            <a :href="route('orders.invoice', selected.active_order.id)" target="_blank" rel="noopener" class="flex-1">
                                <SecondaryButton class="w-full justify-center">{{ $t('staff.invoice') }}</SecondaryButton>
                            </a>
                            <SecondaryButton class="flex-1 justify-center" @click="printOrder(selected.active_order)">
                                {{ $t('staff.print') }}
                            </SecondaryButton>
                        </div>
                        <SecondaryButton
                            v-if="!selected.active_order.is_paid"
                            class="w-full justify-center"
                            @click="showMovePicker = true"
                        >
                            {{ $t('tables.move_table') }}
                        </SecondaryButton>
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
        <div v-if="popupCall" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4" @click.self="dismissPopup">
            <div class="w-full max-w-sm rounded-2xl bg-white p-6 text-center shadow-2xl dark:bg-gray-900">
                <p class="flex items-center justify-center gap-1.5 text-sm font-semibold uppercase tracking-wide text-amber-600 dark:text-amber-400">
                    <NavIcon name="bell" class="h-4 w-4" /> {{ $t('waiter.call_popup_title') }}
                </p>

                <!-- The service's own photo/color is the biggest visual here —
                     with several call types in flight (charcoal, bill, extra
                     chair…) a waiter needs to tell them apart at a glance,
                     not just see "a call happened". The table number rides
                     along as a badge on top of it, since it's still the one
                     fact that decides where to walk. -->
                <div class="relative mx-auto mb-6 mt-4 h-28 w-28">
                    <div
                        class="flex h-full w-full items-center justify-center overflow-hidden rounded-2xl text-5xl font-bold text-white shadow-lg"
                        :style="{ backgroundColor: popupCall.service_color || '#f59e0b' }"
                    >
                        <img v-if="popupCall.service_image_url" :src="popupCall.service_image_url" class="h-full w-full object-cover" :alt="popupCall.service_name ?? ''" />
                        <template v-else-if="popupCall.service_icon">{{ popupCall.service_icon }}</template>
                        <NavIcon v-else name="bell" class="h-12 w-12" />
                    </div>
                    <span class="absolute left-1/2 -bottom-5 -translate-x-1/2 whitespace-nowrap rounded-full bg-white px-4 py-1.5 text-2xl font-extrabold text-gray-900 shadow-md dark:bg-gray-800 dark:text-gray-100">
                        {{ popupCall.table_label ?? '?' }}
                    </span>
                </div>
                <p class="mt-4 text-base font-medium text-gray-900 dark:text-gray-100">
                    {{ callLabel(popupCall) }}
                </p>
                <p v-if="calls.length > 1" class="mt-1 text-xs font-medium text-amber-600 dark:text-amber-400">
                    {{ t('waiter.more_calls_waiting', { count: calls.length - 1 }) }}
                </p>
                <div class="mt-5 flex gap-2">
                    <SecondaryButton class="flex-1 justify-center" type="button" @click="dismissPopup">{{ $t('waiter.dismiss') }}</SecondaryButton>
                    <PrimaryButton class="flex-1 justify-center" type="button" @click="acknowledgeCall(popupCall)">{{ $t('waiter.acknowledge') }}</PrimaryButton>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
