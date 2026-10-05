<script setup>
import { useOrderStatus } from '@/composables/useOrderStatus';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    date: {
        type: String,
        required: true,
    },
    summary: {
        type: Object,
        required: true,
    },
    byWaiter: {
        type: Array,
        required: true,
    },
    byCashier: {
        type: Array,
        required: true,
    },
    orders: {
        type: Array,
        required: true,
    },
});

const page = usePage();
const { t } = useI18n();
const { statusBadgeClasses } = useOrderStatus();

function reload(date) {
    router.get(route('admin.accounting.index'), { date }, { preserveState: true, preserveScroll: true, replace: true });
}

function formatPrice(cents) {
    return `${page.props.branding.currency_symbol}${(cents / 100).toFixed(2)}`;
}

function formatTime(iso) {
    return new Date(iso).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <Head :title="$t('admin.accounting.title')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('admin.accounting.title') }}</template>

        <div class="mb-4">
            <input
                type="date"
                :value="date"
                class="focus-brand rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                @change="reload($event.target.value)"
            />
        </div>

        <!-- Summary -->
        <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-xs text-gray-500">{{ t('staff.orders_count', { count: summary.total_orders }) }}</p>
                <p class="mt-1 text-xl font-semibold text-gray-900 dark:text-gray-100">{{ summary.total_orders }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-xs text-gray-500">{{ t('admin.accounting.collected') }}</p>
                <p class="mt-1 text-xl font-semibold text-teal-600">{{ formatPrice(summary.collected_cents) }}</p>
                <!-- How it arrived, under the figure it makes up. Shown only
                     once a card has actually been taken: a cafe that deals in
                     nothing but cash gets no line telling it so, and a total
                     with "cash 400 / card 0" under it every day trains the eye
                     to skip the line on the day it finally matters. -->
                <p v-if="summary.card_collected_cents" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ t('staff.payment_methods.cash') }} {{ formatPrice(summary.cash_collected_cents) }}
                    <span class="px-1 text-gray-300 dark:text-gray-600">·</span>
                    {{ t('staff.payment_methods.card') }} {{ formatPrice(summary.card_collected_cents) }}
                </p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-xs text-gray-500">{{ t('admin.accounting.outstanding') }}</p>
                <p class="mt-1 text-xl font-semibold text-amber-600">{{ formatPrice(summary.outstanding_cents) }}</p>
            </div>
            <div v-if="summary.rounding_cents !== 0" class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-xs text-gray-500">{{ t('admin.accounting.rounding') }}</p>
                <p class="mt-1 text-xl font-semibold" :class="summary.rounding_cents > 0 ? 'text-red-600' : 'text-teal-600'">
                    {{ formatPrice(Math.abs(summary.rounding_cents)) }}
                </p>
            </div>
        </div>

        <!-- By waiter / by cashier -->
        <div class="mb-6 grid gap-4 lg:grid-cols-2">
            <div class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <h3 class="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-gray-900 dark:border-gray-800 dark:text-gray-100">
                    {{ t('admin.accounting.by_waiter') }}
                </h3>
                <table class="min-w-full divide-y divide-gray-100 dark:divide-gray-800">
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <tr v-if="byWaiter.length === 0">
                            <td class="px-4 py-4 text-center text-sm text-gray-400">{{ t('staff.no_orders') }}</td>
                        </tr>
                        <tr v-for="row in byWaiter" :key="row.name ?? 'self'">
                            <td class="px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300">{{ row.name ?? t('admin.accounting.self_service') }}</td>
                            <td class="px-4 py-2.5 text-end text-sm text-gray-500">{{ t('staff.orders_count', { count: row.orders_count }) }}</td>
                            <td class="px-4 py-2.5 text-end text-sm font-medium tabular-nums text-gray-900 dark:text-gray-100">{{ formatPrice(row.total_cents) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <h3 class="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-gray-900 dark:border-gray-800 dark:text-gray-100">
                    {{ t('admin.accounting.by_cashier') }}
                </h3>
                <table class="min-w-full divide-y divide-gray-100 dark:divide-gray-800">
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <tr v-if="byCashier.length === 0">
                            <td class="px-4 py-4 text-center text-sm text-gray-400">{{ t('admin.accounting.nothing_collected') }}</td>
                        </tr>
                        <tr v-for="row in byCashier" :key="row.name">
                            <td class="px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300">{{ row.name ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-end text-sm text-gray-500">{{ t('staff.orders_count', { count: row.orders_count }) }}</td>
                            <td class="px-4 py-2.5 text-end text-sm font-medium tabular-nums text-gray-900 dark:text-gray-100">{{ formatPrice(row.total_cents) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Order-level detail -->
        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                <thead class="bg-gray-50 dark:bg-gray-900/60">
                    <tr class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-3 text-start">{{ t('common.time') }}</th>
                        <th class="px-4 py-3 text-start">{{ t('common.name') }}</th>
                        <th class="px-4 py-3 text-start">{{ t('tables.title') }}</th>
                        <th class="px-4 py-3 text-start">{{ t('admin.accounting.taken_by') }}</th>
                        <th class="px-4 py-3 text-start">{{ t('admin.accounting.collected_by') }}</th>
                        <th class="px-4 py-3 text-start">{{ t('common.status') }}</th>
                        <th class="px-4 py-3 text-end">{{ t('common.price') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                    <tr v-if="orders.length === 0">
                        <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-400">{{ t('staff.no_orders') }}</td>
                    </tr>
                    <tr v-for="order in orders" :key="order.id">
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-500">{{ formatTime(order.created_at) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">{{ order.reference }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{{ order.table_label ?? '—' }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{{ order.created_by_name ?? t('admin.accounting.self_service') }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{{ order.paid_by_name ?? '—' }}</td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="statusBadgeClasses(order.status_color)">
                                {{ t(`orders.statuses.${order.status}`) }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-end text-sm tabular-nums text-gray-900 dark:text-gray-100">
                            {{ formatPrice(order.total_cents) }}
                            <div
                                v-if="order.amount_paid_cents !== null && order.amount_paid_cents !== order.total_cents"
                                class="text-xs font-normal"
                                :class="order.amount_paid_cents < order.total_cents ? 'text-red-500' : 'text-teal-600'"
                            >
                                {{ t('admin.accounting.received') }} {{ formatPrice(order.amount_paid_cents) }}
                            </div>
                            <!-- A note in the drawer and a charge landing in
                                 the bank on Thursday are both "collected",
                                 and on the screen that exists to trace the
                                 money they must not look the same. -->
                            <div v-if="order.payment_method" class="text-xs font-normal text-gray-500 dark:text-gray-400">
                                {{ t(`staff.payment_methods.${order.payment_method}`) }}
                                <span v-if="order.payment_reference" class="tabular-nums" dir="ltr">· {{ order.payment_reference }}</span>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AuthenticatedLayout>
</template>
