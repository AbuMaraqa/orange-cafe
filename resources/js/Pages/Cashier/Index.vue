<script setup>
import { computed } from 'vue';
import OrderCard from '@/Components/Staff/OrderCard.vue';
import { nextStatus } from '@/constants/orderStatus';
import { useLiveOrders } from '@/composables/useLiveOrders';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    orders: {
        type: Array,
        required: true,
    },
});

const { t } = useI18n();
const page = usePage();
const { orders } = useLiveOrders(() => props.orders);

// Unpaid tables are the cashier's actual to-do list — surface those first,
// most recent on top; already-collected ones sink to the bottom.
const sorted = computed(() =>
    [...orders]
        .filter((o) => o.status !== 'cancelled')
        .sort((a, b) => {
            if (a.is_paid !== b.is_paid) return a.is_paid ? 1 : -1;
            return new Date(b.created_at) - new Date(a.created_at);
        }),
);

const outstandingCents = computed(() =>
    orders.filter((o) => !o.is_paid && o.status !== 'cancelled').reduce((sum, o) => sum + o.total_cents, 0),
);

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

function removeItem(order, item) {
    if (!confirm(`${item.product_name}: ${t('common.are_you_sure')}`)) return;
    router.delete(route('orders.items.destroy', [order.id, item.id]), { preserveScroll: true });
}
</script>

<template>
    <Head :title="$t('nav.cashier')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('cashier.title') }}</template>

        <div v-if="sorted.length > 0" class="mb-4 flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <span class="text-sm text-gray-500">{{ t('staff.orders_count', { count: sorted.length }) }}</span>
            <span class="text-sm font-semibold text-amber-600">{{ t('cashier.outstanding') }}: {{ formatPrice(outstandingCents) }}</span>
        </div>

        <p v-if="sorted.length === 0" class="text-sm text-gray-500">{{ $t('staff.no_orders') }}</p>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <OrderCard
                v-for="order in sorted"
                :key="order.id"
                :order="order"
                :next-status-label="statusLabel(order.status)"
                show-pay
                show-invoice
                removable-items
                show-item-prices
                @advance="advance(order)"
                @pay="markPaid(order, $event)"
                @remove-item="removeItem(order, $event)"
            />
        </div>
    </AuthenticatedLayout>
</template>
