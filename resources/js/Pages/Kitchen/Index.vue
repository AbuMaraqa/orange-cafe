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
const { orders } = useLiveOrders(() => props.orders);

// Kitchen only cares about tickets it still needs to act on.
const kitchenOrders = computed(() =>
    orders
        .filter((o) => ['pending', 'confirmed', 'preparing'].includes(o.status))
        .sort((a, b) => new Date(a.created_at) - new Date(b.created_at)),
);

function statusLabel(status) {
    const next = nextStatus(status);
    return next ? t(`orders.statuses.${next}`) : null;
}

function advance(order) {
    const next = nextStatus(order.status);
    if (!next) return;

    router.patch(route('orders.status', order.id), { status: next }, { preserveScroll: true });
}

function printOrder(order) {
    router.post(route('orders.print', order.id), {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="$t('nav.kitchen')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('kitchen.title') }}</template>

        <p v-if="kitchenOrders.length === 0" class="text-sm text-gray-500">{{ $t('staff.no_orders') }}</p>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <OrderCard
                v-for="order in kitchenOrders"
                :key="order.id"
                :order="order"
                :next-status-label="statusLabel(order.status)"
                show-print
                @advance="advance(order)"
                @print="printOrder(order)"
            />
        </div>
    </AuthenticatedLayout>
</template>
