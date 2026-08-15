<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import NavIcon from '@/Components/NavIcon.vue';
import { useOrderStatus } from '@/composables/useOrderStatus';

const props = defineProps({
    order: {
        type: Object,
        required: true,
    },
});

const page = usePage();
const { t } = useI18n();
const { statusBadgeClasses } = useOrderStatus();

function formatPrice(cents) {
    return `${page.props.branding.currency_symbol}${(cents / 100).toFixed(2)}`;
}
</script>

<template>
    <Head :title="order.reference" />

    <div class="mx-auto min-h-screen max-w-md bg-gray-50 px-4 py-10 dark:bg-gray-950">
        <div class="rounded-lg border border-gray-200 bg-white p-6 text-center dark:border-gray-800 dark:bg-gray-900">
            <div
                class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full text-white"
                style="background-color: var(--brand-primary)"
            ><NavIcon name="check" class="h-6 w-6" /></div>
            <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ t('menu.order_placed') }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ order.reference }}</p>

            <span
                class="mt-3 inline-flex rounded-full px-3 py-1 text-xs font-medium"
                :class="statusBadgeClasses(order.status_color)"
            >
                {{ t(`orders.statuses.${order.status}`) }}
            </span>
        </div>

        <div class="mt-4 rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
            <div v-for="(item, index) in order.items" :key="index" class="mb-3 flex items-start justify-between gap-3 border-b border-gray-100 pb-3 last:mb-0 last:border-0 last:pb-0 dark:border-gray-800">
                <div>
                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ item.quantity }}× {{ item.product_name }}</p>
                    <p v-if="item.modifiers.length" class="text-xs text-gray-500">{{ item.modifiers.map((m) => m.name).join(', ') }}</p>
                </div>
                <p class="shrink-0 text-sm text-gray-700 dark:text-gray-300">{{ formatPrice(item.subtotal_cents) }}</p>
            </div>

            <div class="mt-3 flex items-center justify-between border-t border-gray-200 pt-3 font-semibold text-gray-900 dark:border-gray-800 dark:text-gray-100">
                <span>{{ t('common.price') }}</span>
                <span>{{ formatPrice(order.total_cents) }}</span>
            </div>
        </div>

        <Link :href="route('menu.index')" class="mt-6 block text-center text-sm text-gray-500 underline">
            {{ t('menu.back_to_menu') }}
        </Link>
    </div>
</template>
