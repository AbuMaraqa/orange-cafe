<script setup>
import ProductCard from '@/Components/Customer/ProductCard.vue';

/**
 * One menu section and everything beneath it, however deep the nesting goes.
 *
 * The customer menu used to render exactly two levels — a section and its
 * subsections — because the markup was written out twice by hand. A cafe
 * that grouped its menu as "مشروبات ← ساخنة ← قهوة" lost the third level
 * entirely: those products existed in the admin panel and no customer could
 * ever see, let alone order, them.
 *
 * The component renders itself for each child, so depth is whatever the
 * data says it is.
 */
defineProps({
    category: {
        type: Object,
        required: true,
    },
    branding: {
        type: Object,
        required: true,
    },
    /**
     * How far down the tree this section sits. Drives the heading size only:
     * a subsection has to read as subordinate to its parent, but past the
     * third level the type would get too small to read, so it stops shrinking.
     */
    depth: {
        type: Number,
        default: 0,
    },
});

defineEmits(['add']);

const HEADING = [
    'text-lg font-semibold tracking-tight text-gray-900 dark:text-gray-100',
    'text-sm font-semibold uppercase tracking-widest text-gray-500 dark:text-gray-400',
    'text-xs font-semibold uppercase tracking-widest text-gray-400',
];

const headingClass = (depth) => HEADING[Math.min(depth, HEADING.length - 1)];
</script>

<template>
    <section :class="depth === 0 ? 'mb-10' : 'mt-6'">
        <!-- Only the top level gets the full-width rule; repeating it at every
             depth turns a nested menu into a stack of horizontal lines. -->
        <div v-if="depth === 0" class="mb-1 flex items-center gap-3">
            <h2 :class="headingClass(0)" class="shrink-0">{{ category.name }}</h2>
            <span class="h-px flex-1" :style="{ backgroundColor: branding.primary_color, opacity: 0.25 }"></span>
        </div>

        <h3 v-else :class="headingClass(depth)" class="mb-1">{{ category.name }}</h3>

        <p v-if="category.description" class="mb-2 text-sm text-gray-500">{{ category.description }}</p>

        <div v-if="category.products.length > 0" class="flex flex-col gap-3">
            <ProductCard
                v-for="product in category.products"
                :key="product.id"
                :product="product"
                :currency-symbol="branding.currency_symbol"
                :logo-url="branding.logo_url"
                @add="$emit('add', $event)"
            />
        </div>

        <!-- Indented one step per level so the hierarchy is visible rather
             than merely implied by the heading size. Capped, or a deep menu
             would walk itself off the side of a phone. -->
        <div
            v-for="child in category.subcategories"
            :key="child.id"
            :class="depth < 2 ? 'ps-3 border-s border-gray-100 dark:border-gray-800' : ''"
        >
            <MenuSection
                :category="child"
                :branding="branding"
                :depth="depth + 1"
                @add="$emit('add', $event)"
            />
        </div>
    </section>
</template>
