<script setup>
import { computed } from 'vue';
import OrderCard from '@/Components/Staff/OrderCard.vue';
import { nextStatus } from '@/constants/orderStatus';
import { useLiveOrders } from '@/composables/useLiveOrders';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    orders: {
        type: Array,
        required: true,
    },
});

const { t } = useI18n();
const { orders } = useLiveOrders(props.orders);

const sorted = computed(() => [...orders].sort((a, b) => new Date(b.created_at) - new Date(a.created_at)));

function statusLabel(status) {
    const next = nextStatus(status);
    return next ? t(`orders.statuses.${next}`) : null;
}

function advance(order) {
    const next = nextStatus(order.status);
    if (!next) return;

    router.patch(route('orders.status', order.id), { status: next }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="$t('nav.waiter')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('waiter.title') }}</template>

        <p v-if="sorted.length === 0" class="text-sm text-gray-500">{{ $t('staff.no_orders') }}</p>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <OrderCard
                v-for="order in sorted"
                :key="order.id"
                :order="order"
                :next-status-label="statusLabel(order.status)"
                @advance="advance(order)"
            />
        </div>
    </AuthenticatedLayout>
</template>
