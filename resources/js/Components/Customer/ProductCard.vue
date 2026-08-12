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
        class="group flex flex-col overflow-hidden rounded-xl border border-gray-100 bg-white text-start shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-gray-800 dark:bg-gray-900"
        @click="emit('add', product)"
    >
        <div class="relative aspect-[4/3] w-full overflow-hidden bg-gray-100 dark:bg-gray-800">
            <img
                v-if="product.image_url"
                :src="product.image_url"
                :alt="product.name"
                class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
            />
            <div v-else class="flex h-full w-full items-center justify-center text-3xl text-gray-300 dark:text-gray-700">🍽</div>

            <span
                v-if="product.is_featured"
                class="absolute start-2 top-2 inline-flex items-center gap-1 rounded-full bg-white/90 px-2 py-0.5 text-[11px] font-semibold text-amber-600 shadow-sm backdrop-blur"
            >✦ {{ t('menu.featured') }}</span>
        </div>

        <div class="flex flex-1 flex-col p-3">
            <h4 class="line-clamp-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ product.name }}</h4>
            <p v-if="product.description" class="mt-0.5 line-clamp-2 text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                {{ product.description }}
            </p>

            <div class="mt-auto flex items-center justify-between pt-2">
                <span class="font-semibold tabular-nums" style="color: var(--brand-primary)">{{ formatPrice(product.price_cents) }}</span>
                <span v-if="product.modifier_groups.length" class="text-[11px] text-gray-400">{{ t('menu.customizable') }}</span>
            </div>

            <div v-if="product.tags.length" class="mt-1.5 flex flex-wrap gap-1">
                <span v-for="tag in product.tags" :key="tag.id" class="text-[11px] text-gray-400">{{ tag.icon }} {{ tag.name }}</span>
            </div>
        </div>
    </button>
</template>
