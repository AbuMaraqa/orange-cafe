<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useOrderStatus } from '@/composables/useOrderStatus';
import { useI18n } from 'vue-i18n';

defineProps({
    order: {
        type: Object,
        required: true,
    },
    nextStatusLabel: {
        type: String,
        default: null,
    },
    showPay: {
        type: Boolean,
        default: false,
    },
    showInvoice: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['advance', 'pay']);
const { t } = useI18n();
const { statusBadgeClasses } = useOrderStatus();

function formatPrice(cents) {
    return (cents / 100).toFixed(2);
}

function timeAgo(iso) {
    const minutes = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000));
    return minutes < 1 ? t('staff.just_now') : t('staff.minutes_ago', { minutes });
}
</script>

<template>
    <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
        <div class="flex items-start justify-between">
            <div>
                <p class="font-semibold text-gray-900 dark:text-gray-100">
                    {{ order.table_label ? `${t('tables.title')} ${order.table_label}` : order.reference }}
                </p>
                <p class="text-xs text-gray-500">{{ order.reference }} · {{ timeAgo(order.created_at) }}</p>
            </div>
            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="statusBadgeClasses(order.status_color)">
                {{ t(`orders.statuses.${order.status}`) }}
            </span>
        </div>

        <ul class="mt-3 space-y-1 text-sm text-gray-700 dark:text-gray-300">
            <li v-for="(item, index) in order.items" :key="index">
                <span class="font-medium">{{ item.quantity }}×</span> {{ item.product_name }}
                <span v-if="item.modifiers.length" class="text-gray-400">({{ item.modifiers.join(', ') }})</span>
                <div v-if="item.notes" class="ms-4 text-xs italic text-gray-400">{{ item.notes }}</div>
            </li>
        </ul>
        <p v-if="order.notes" class="mt-2 text-xs italic text-gray-500">{{ order.notes }}</p>

        <div class="mt-3 flex items-center justify-between border-t border-gray-100 pt-3 dark:border-gray-800">
            <span class="font-semibold text-gray-900 dark:text-gray-100">{{ formatPrice(order.total_cents) }}</span>
            <div class="flex gap-2">
                <a v-if="showInvoice" :href="route('orders.invoice', order.id)" target="_blank" rel="noopener">
                    <SecondaryButton type="button">{{ t('staff.invoice') }}</SecondaryButton>
                </a>
                <SecondaryButton v-if="showPay && !order.is_paid" type="button" @click="emit('pay')">
                    {{ t('staff.mark_paid') }}
                </SecondaryButton>
                <span v-else-if="showPay && order.is_paid" class="self-center text-xs font-medium text-teal-600">
                    {{ t('tables.paid') }}
                </span>
                <PrimaryButton v-if="nextStatusLabel" type="button" @click="emit('advance')">
                    {{ nextStatusLabel }}
                </PrimaryButton>
            </div>
        </div>
    </div>
</template>
