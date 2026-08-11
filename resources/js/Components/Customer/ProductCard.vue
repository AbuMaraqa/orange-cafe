<script setup>
import { useI18n } from 'vue-i18n';

const props = defineProps({
    product: {
        type: Object,
        required: true,
    },
    currencySymbol: {
        type: String,
        required: true,
    },
});

const emit = defineEmits(['add']);
const { t } = useI18n();

function formatPrice(cents) {
    return `${props.currencySymbol}${(cents / 100).toFixed(2)}`;
}
</script>

<template>
    <button
        type="button"
        class="flex w-full items-start gap-3 rounded-lg border border-gray-200 bg-white p-3 text-start transition hover:border-gray-300 hover:shadow-sm dark:border-gray-800 dark:bg-gray-900 dark:hover:border-gray-700"
        @click="emit('add', product)"
    >
        <div class="h-16 w-16 shrink-0 overflow-hidden rounded-md bg-gray-100 dark:bg-gray-800">
            <img v-if="product.image_url" :src="product.image_url" :alt="product.name" class="h-full w-full object-cover" />
            <div v-else class="flex h-full w-full items-center justify-center text-gray-300 dark:text-gray-700">🍽</div>
        </div>

        <div class="min-w-0 flex-1">
            <p class="truncate font-medium text-gray-900 dark:text-gray-100">{{ product.name }}</p>
            <p v-if="product.description" class="mt-0.5 line-clamp-2 text-xs text-gray-500">{{ product.description }}</p>
            <div v-if="product.tags.length" class="mt-1 flex flex-wrap gap-1">
                <span v-for="tag in product.tags" :key="tag.id" class="text-xs text-gray-400">{{ tag.icon }} {{ tag.name }}</span>
            </div>
            <p class="mt-1.5 font-semibold text-gray-900 dark:text-gray-100">
                {{ formatPrice(product.price_cents) }}
                <span v-if="product.modifier_groups.length" class="ms-1 text-xs font-normal text-gray-400">{{ t('menu.customizable') }}</span>
            </p>
        </div>
    </button>
</template>
