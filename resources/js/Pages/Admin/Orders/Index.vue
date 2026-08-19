<script setup>
import NavIcon from '@/Components/NavIcon.vue';
import { useOrderStatus } from '@/composables/useOrderStatus';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    orders: {
        type: Array,
        required: true,
    },
    date: {
        type: String,
        required: true,
    },
    status: {
        type: String,
        default: null,
    },
    statusCounts: {
        type: Object,
        required: true,
    },
    summary: {
        type: Object,
        required: true,
    },
});

const page = usePage();
const { t } = useI18n();
const { statusBadgeClasses } = useOrderStatus();

function reload(overrides = {}) {
    router.get(route('admin.orders.index'), {
        date: overrides.date ?? props.date,
        status: 'status' in overrides ? overrides.status : props.status,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

function formatPrice(cents) {
    return `${page.props.branding.currency_symbol}${(cents / 100).toFixed(2)}`;
}

function formatTime(iso) {
    return new Date(iso).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
}

function hasNotes(order) {
    return Boolean(order.notes) || order.items.some((item) => item.notes);
}

const expandedId = ref(null);

function toggleExpanded(order) {
    expandedId.value = expandedId.value === order.id ? null : order.id;
}
</script>

<template>
    <Head :title="$t('nav.orders')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('nav.orders') }}</template>

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <input
                type="date"
                :value="date"
                class="focus-brand rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                @change="reload({ date: $event.target.value })"
            />

            <div class="flex gap-3 text-sm text-gray-500">
                <span>{{ t('staff.orders_count', { count: summary.total_orders }) }}</span>
                <span class="font-medium text-gray-900 dark:text-gray-100">{{ formatPrice(summary.paid_revenue_cents) }} {{ t('staff.collected') }}</span>
            </div>
        </div>

        <div class="mb-4 flex gap-2 overflow-x-auto pb-1">
            <button
                type="button"
                class="shrink-0 rounded-full border px-3.5 py-1.5 text-sm font-medium"
                :class="!status ? 'border-transparent text-white' : 'border-gray-200 text-gray-600 dark:border-gray-700 dark:text-gray-300'"
                :style="!status ? { backgroundColor: 'var(--brand-primary)' } : {}"
                @click="reload({ status: null })"
            >
                {{ t('menu.all_categories') }} ({{ Object.values(statusCounts).reduce((a, b) => a + b, 0) }})
            </button>
            <button
                v-for="(count, key) in statusCounts"
                :key="key"
                type="button"
                class="shrink-0 rounded-full border px-3.5 py-1.5 text-sm font-medium"
                :class="status === key ? 'border-transparent text-white' : 'border-gray-200 text-gray-600 dark:border-gray-700 dark:text-gray-300'"
                :style="status === key ? { backgroundColor: 'var(--brand-primary)' } : {}"
                @click="reload({ status: key })"
            >
                {{ t(`orders.statuses.${key}`) }} ({{ count }})
            </button>
        </div>

        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                <thead class="bg-gray-50 dark:bg-gray-900/60">
                    <tr class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-3 text-start">{{ t('common.time') }}</th>
                        <th class="px-4 py-3 text-start">{{ t('common.name') }}</th>
                        <th class="px-4 py-3 text-start">{{ t('tables.title') }}</th>
                        <th class="px-4 py-3 text-start">{{ t('common.status') }}</th>
                        <th class="px-4 py-3 text-start">{{ t('tables.paid') }}</th>
                        <th class="px-4 py-3 text-end">{{ t('common.price') }}</th>
                        <th class="px-4 py-3 text-end">{{ t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                    <tr v-if="orders.length === 0">
                        <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-400">{{ t('staff.no_orders') }}</td>
                    </tr>
                    <template v-for="order in orders" :key="order.id">
                        <tr
                            class="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/40"
                            @click="toggleExpanded(order)"
                        >
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-500">{{ formatTime(order.created_at) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">
                                <span class="inline-flex items-center gap-1.5">
                                    {{ order.reference }}
                                    <NavIcon
                                        v-if="hasNotes(order)"
                                        name="note"
                                        class="h-3.5 w-3.5 shrink-0"
                                        :class="expandedId === order.id ? 'text-gray-900 dark:text-gray-100' : 'text-amber-500'"
                                        :title="t('menu.notes')"
                                    />
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{{ order.table_label ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="statusBadgeClasses(order.status_color)">
                                    {{ t(`orders.statuses.${order.status}`) }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm">
                                <span :class="order.is_paid ? 'text-teal-600' : 'text-gray-400'">
                                    {{ order.is_paid ? t('tables.paid') : t('tables.unpaid') }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-end text-sm tabular-nums text-gray-900 dark:text-gray-100">
                                {{ formatPrice(order.total_cents) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-end text-sm" @click.stop>
                                <a :href="route('orders.invoice', order.id)" target="_blank" rel="noopener" class="text-gray-500 underline hover:text-gray-800 dark:hover:text-gray-200">
                                    {{ t('staff.invoice') }}
                                </a>
                            </td>
                        </tr>
                        <tr v-if="expandedId === order.id" class="bg-gray-50 dark:bg-gray-800/40">
                            <td colspan="7" class="px-4 py-3">
                                <ul class="space-y-1.5 text-sm text-gray-700 dark:text-gray-300">
                                    <li v-for="item in order.items" :key="item.id">
                                        <span class="font-medium">{{ item.quantity }}×</span> {{ item.product_name }}
                                        <span v-if="item.modifiers.length" class="text-gray-400">({{ item.modifiers.join(', ') }})</span>
                                        <div v-if="item.notes" class="ms-4 flex items-start gap-1 text-xs italic text-amber-600 dark:text-amber-500">
                                            <NavIcon name="note" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                            {{ item.notes }}
                                        </div>
                                    </li>
                                </ul>
                                <div v-if="order.notes" class="mt-2 flex items-start gap-1.5 border-t border-gray-200 pt-2 text-sm italic text-amber-700 dark:border-gray-700 dark:text-amber-500">
                                    <NavIcon name="note" class="mt-0.5 h-4 w-4 shrink-0" />
                                    {{ order.notes }}
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </AuthenticatedLayout>
</template>
