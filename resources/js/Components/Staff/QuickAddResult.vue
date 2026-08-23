<script setup>
import NavIcon from '@/Components/NavIcon.vue';

/**
 * One searchable dish, as dense as it can be and still be tappable.
 *
 * The staff order screen used the customer's product card: a photo tile,
 * one or two per row. That card is right for a customer browsing and wrong
 * for a waiter who already knows what they want — four dishes filled the
 * screen, so every order meant scrolling, and the photo carried no
 * information the waiter needed.
 *
 * This is a row: name, where it lives, price. Around ten fit in the space
 * one card used, and the whole row is the button.
 */
defineProps({
    product: { type: Object, required: true },
    currencySymbol: { type: String, default: '' },
    /** Where it sits on the menu — two dishes can share a name. */
    path: { type: String, default: '' },
    /** Keyboard cursor: Enter adds this one. */
    highlighted: { type: Boolean, default: false },
    /** How many of it are already in the order. */
    inCart: { type: Number, default: 0 },
});

defineEmits(['add']);
</script>

<template>
    <button
        type="button"
        class="flex w-full items-center gap-3 rounded-lg border px-3 py-2.5 text-start transition"
        :class="highlighted
            ? 'border-transparent ring-2 ring-offset-1 dark:ring-offset-gray-900'
            : 'border-gray-200 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800'"
        :style="highlighted
            ? { '--tw-ring-color': 'var(--brand-primary)', backgroundColor: 'color-mix(in srgb, var(--brand-primary) 8%, transparent)' }
            : {}"
        @click="$emit('add', product)"
    >
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">{{ product.name }}</p>
            <p v-if="path" class="truncate text-[11px] text-gray-400">{{ path }}</p>
        </div>

        <!-- Already on the order. Without it the waiter has no way to tell a
             second tap from a first, and double-adding is the mistake this
             screen makes easiest. -->
        <span
            v-if="inCart > 0"
            class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold text-white"
            :style="{ backgroundColor: 'var(--brand-primary)' }"
        >{{ inCart }}</span>

        <span class="shrink-0 text-sm font-bold tabular-nums text-gray-700 dark:text-gray-200">
            {{ currencySymbol }}{{ (product.price_cents / 100).toFixed(2) }}
        </span>

        <!-- A dish with required choices opens a sheet instead of dropping
             straight in; the icon says so before it is tapped. -->
        <NavIcon
            :name="product.modifier_groups?.length ? 'sliders' : 'plus'"
            class="h-4 w-4 shrink-0 text-gray-400"
        />
    </button>
</template>
