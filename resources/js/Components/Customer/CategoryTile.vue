<script setup>
/**
 * One section of the menu, as a round photo tile.
 *
 * A café with a hundred and fifty items cannot be a scroll — a customer at a
 * table with friends will not read a wall of text to find a cold coffee.
 * Sections are browsed the way a paper menu is: you look at a picture, you
 * open it, and what is inside is only what belongs there.
 *
 * The tile falls back photo → café logo → the section's initial in the brand
 * colour, rather than mixing a photo style and a text style side by side,
 * which reads as a page that failed to load rather than a design.
 */
defineProps({
    category: { type: Object, required: true },
    branding: { type: Object, required: true },
    // Shared with the page so a broken image is only discovered once, not
    // once per tile that happens to use the same logo.
    failedImages: { type: Object, required: true },
    size: { type: String, default: 'lg' },
});

defineEmits(['open']);
</script>

<template>
    <button
        type="button"
        class="group flex flex-col items-center gap-2 outline-none"
        @click="$emit('open', category)"
    >
        <span
            class="relative flex items-center justify-center overflow-hidden rounded-full shadow-sm ring-2 ring-transparent ring-offset-2 transition-all duration-200 group-hover:-translate-y-0.5 group-hover:shadow-md group-focus-visible:ring-[var(--brand-primary)] dark:ring-offset-gray-950"
            :class="size === 'lg' ? 'h-24 w-24' : 'h-16 w-16'"
        >
            <img
                v-if="category.image_url && !failedImages.has(`category-${category.id}`)"
                :src="category.image_url"
                :alt="category.name"
                class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                @error="failedImages.add(`category-${category.id}`)"
            />
            <span
                v-else-if="branding.logo_url && !failedImages.has('logo')"
                class="flex h-full w-full items-center justify-center bg-white p-3 dark:bg-gray-900"
            >
                <img :src="branding.logo_url" :alt="category.name" class="h-full w-full object-contain opacity-70" @error="failedImages.add('logo')" />
            </span>
            <span
                v-else
                class="flex h-full w-full items-center justify-center font-bold text-white"
                :class="size === 'lg' ? 'text-2xl' : 'text-lg'"
                :style="{ backgroundColor: branding.primary_color }"
            >
                {{ category.name.charAt(0) }}
            </span>

            <!-- A section holding more sections gets a mark, so it is clear
                 before tapping that this opens onto more choices rather than
                 straight onto dishes. -->
            <span
                v-if="category.subcategories?.length"
                class="absolute bottom-0 end-0 flex h-6 w-6 items-center justify-center rounded-full border-2 border-white text-[10px] font-bold text-white shadow dark:border-gray-950"
                :style="{ backgroundColor: branding.primary_color }"
            >
                {{ category.subcategories.length }}
            </span>
        </span>

        <span class="flex flex-col items-center gap-0.5">
            <span class="line-clamp-2 max-w-[6rem] text-center text-[13px] font-semibold leading-tight text-gray-900 dark:text-gray-100">
                {{ category.name }}
            </span>
            <span v-if="category.product_count" class="text-[11px] text-gray-400">
                {{ category.product_count }}
            </span>
        </span>
    </button>
</template>
