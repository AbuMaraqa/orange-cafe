<script setup>
/**
 * Picking products to drop into a menu section.
 *
 * This replaces a plain <select> of every product in the database, one
 * add per page reload. That was fine for a demo catalog and unusable for
 * a real one: no search, no way to tell two similarly named drinks
 * apart, and no sense of what a product carries with it (its tags, its
 * modifier groups, whether it is even switched on).
 *
 * Everything shown here is data the cafe already maintains elsewhere in
 * the admin panel — it is only surfaced at the moment the decision is
 * actually being made.
 */
import { computed, nextTick, ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import NavIcon from '@/Components/NavIcon.vue';
import { usePage } from '@inertiajs/vue3';

const props = defineProps({
    show: { type: Boolean, default: false },
    section: { type: Object, default: null },
    products: { type: Array, required: true },
    tags: { type: Array, default: () => [] },
    processing: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'add']);

const page = usePage();

const search = ref('');
const activeTagIds = ref([]);
const hideInactive = ref(true);
const selectedIds = ref([]);
const priceOverride = ref('');
const searchInput = ref(null);

function formatPrice(cents) {
    return `${page.props.branding.currency_symbol}${(cents / 100).toFixed(2)}`;
}

// The products already sitting in *this* section. A product may live in
// several sections at once (that is the whole point of menu_items), so
// this is deliberately not "everything already in the menu".
const placedIds = computed(
    () => new Set((props.section?.items ?? []).map((item) => item.product_id)),
);

const filtered = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.products.filter((product) => {
        if (hideInactive.value && !product.is_active && !placedIds.value.has(product.id)) {
            return false;
        }

        if (term && !product.name.toLowerCase().includes(term)) {
            return false;
        }

        // Any-of rather than all-of: tags here are "vegan", "spicy",
        // "new" — an administrator narrowing by two of them wants both
        // shortlists, not their intersection.
        if (activeTagIds.value.length && !product.tag_ids.some((id) => activeTagIds.value.includes(id))) {
            return false;
        }

        return true;
    });
});

const selectableFiltered = computed(() => filtered.value.filter((p) => !placedIds.value.has(p.id)));

const allFilteredSelected = computed(
    () => selectableFiltered.value.length > 0
        && selectableFiltered.value.every((p) => selectedIds.value.includes(p.id)),
);

function toggle(product) {
    if (placedIds.value.has(product.id)) return;

    const index = selectedIds.value.indexOf(product.id);
    if (index === -1) {
        selectedIds.value.push(product.id);
    } else {
        selectedIds.value.splice(index, 1);
    }
}

function toggleTag(tagId) {
    const index = activeTagIds.value.indexOf(tagId);
    if (index === -1) {
        activeTagIds.value.push(tagId);
    } else {
        activeTagIds.value.splice(index, 1);
    }
}

/**
 * Selects every product the current filter leaves on screen — the
 * shortest path from "all our cold drinks" to a populated section.
 */
function toggleSelectAll() {
    if (allFilteredSelected.value) {
        const filteredIds = new Set(selectableFiltered.value.map((p) => p.id));
        selectedIds.value = selectedIds.value.filter((id) => !filteredIds.has(id));
        return;
    }

    const merged = new Set(selectedIds.value);
    selectableFiltered.value.forEach((p) => merged.add(p.id));
    selectedIds.value = [...merged];
}

function reset() {
    search.value = '';
    activeTagIds.value = [];
    selectedIds.value = [];
    priceOverride.value = '';
    hideInactive.value = true;
}

watch(() => props.show, (visible) => {
    if (visible) {
        reset();
        nextTick(() => searchInput.value?.focus());
    }
});

function submit() {
    if (!selectedIds.value.length) return;

    emit('add', {
        product_ids: [...selectedIds.value],
        // A per-menu price override is only meaningful for a single
        // line; a batch takes each product's own price and can be
        // adjusted per row afterwards.
        price_cents: selectedIds.value.length === 1 && priceOverride.value !== ''
            ? Math.round(Number(priceOverride.value) * 100)
            : null,
    });
}
</script>

<template>
    <Modal :show="show" max-width="4xl" @close="emit('close')">
        <div class="flex max-h-[80vh] flex-col">
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-700">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h2 class="truncate text-base font-semibold text-gray-900 dark:text-gray-100">
                            {{ $t('admin.menus.add_products_to', { section: section?.name ?? '' }) }}
                        </h2>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            {{ $t('admin.menus.picker_hint') }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="shrink-0 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                        @click="emit('close')"
                    >
                        <NavIcon name="close" class="h-5 w-5" />
                    </button>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <div class="relative min-w-0 flex-1">
                        <NavIcon
                            name="search"
                            class="pointer-events-none absolute top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400 ltr:left-3 rtl:right-3"
                        />
                        <input
                            ref="searchInput"
                            v-model="search"
                            type="search"
                            :placeholder="$t('admin.menus.search_products')"
                            class="focus-brand w-full rounded-md border-gray-300 py-1.5 text-sm shadow-sm ltr:pl-9 rtl:pr-9 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200"
                        />
                    </div>

                    <label class="flex shrink-0 items-center gap-1.5 text-xs text-gray-600 dark:text-gray-400">
                        <input
                            v-model="hideInactive"
                            type="checkbox"
                            class="focus-brand rounded border-gray-300 dark:border-gray-600 dark:bg-gray-900"
                        />
                        {{ $t('admin.menus.hide_inactive_products') }}
                    </label>
                </div>

                <div v-if="tags.length" class="mt-2.5 flex flex-wrap gap-1.5">
                    <button
                        v-for="tag in tags"
                        :key="tag.id"
                        type="button"
                        class="rounded-full border px-2.5 py-0.5 text-xs transition"
                        :class="activeTagIds.includes(tag.id)
                            ? 'border-transparent bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900'
                            : 'border-gray-300 text-gray-600 hover:border-gray-400 dark:border-gray-700 dark:text-gray-400'"
                        @click="toggleTag(tag.id)"
                    >
                        <span v-if="tag.icon" class="me-1">{{ tag.icon }}</span>{{ tag.name }}
                    </button>
                </div>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                <div v-if="filtered.length === 0" class="py-10 text-center text-sm text-gray-400">
                    {{ $t('admin.menus.no_products_match') }}
                </div>

                <div v-else class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    <button
                        v-for="product in filtered"
                        :key="product.id"
                        type="button"
                        :disabled="placedIds.has(product.id)"
                        class="flex items-start gap-3 rounded-lg border p-2.5 text-start transition"
                        :class="placedIds.has(product.id)
                            ? 'cursor-not-allowed border-gray-200 bg-gray-50 opacity-60 dark:border-gray-800 dark:bg-gray-900/40'
                            : selectedIds.includes(product.id)
                                ? 'border-gray-900 bg-gray-50 dark:border-gray-100 dark:bg-gray-800'
                                : 'border-gray-200 hover:border-gray-400 dark:border-gray-800 dark:hover:border-gray-600'"
                        @click="toggle(product)"
                    >
                        <div class="relative h-12 w-12 shrink-0 overflow-hidden rounded-md bg-gray-100 dark:bg-gray-800">
                            <img v-if="product.image_url" :src="product.image_url" class="h-full w-full object-cover" />
                            <img
                                v-else-if="page.props.branding.logo_url"
                                :src="page.props.branding.logo_url"
                                class="h-full w-full object-contain p-1 opacity-60"
                            />
                            <span
                                v-if="selectedIds.includes(product.id)"
                                class="absolute inset-0 flex items-center justify-center bg-gray-900/70 text-white"
                            >
                                <NavIcon name="check" class="h-5 w-5" />
                            </span>
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-baseline justify-between gap-2">
                                <span class="truncate text-sm font-medium text-gray-800 dark:text-gray-200">
                                    {{ product.name }}
                                </span>
                                <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">
                                    {{ formatPrice(product.price_cents) }}
                                </span>
                            </div>

                            <div class="mt-1 flex flex-wrap items-center gap-1">
                                <span
                                    v-if="placedIds.has(product.id)"
                                    class="rounded bg-gray-200 px-1.5 py-0.5 text-[10px] text-gray-600 dark:bg-gray-700 dark:text-gray-300"
                                >
                                    {{ $t('admin.menus.already_in_section') }}
                                </span>
                                <span
                                    v-if="!product.is_active"
                                    class="rounded bg-red-100 px-1.5 py-0.5 text-[10px] text-red-700 dark:bg-red-900/40 dark:text-red-400"
                                >
                                    {{ $t('common.inactive') }}
                                </span>
                                <span
                                    v-for="tag in product.tags"
                                    :key="tag.id"
                                    class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-600 dark:bg-gray-800 dark:text-gray-400"
                                >
                                    <span v-if="tag.icon" class="me-0.5">{{ tag.icon }}</span>{{ tag.name }}
                                </span>
                                <span
                                    v-if="product.modifier_group_count > 0"
                                    class="inline-flex items-center rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-600 dark:bg-gray-800 dark:text-gray-400"
                                    :title="$t('admin.menus.modifier_groups_count', { count: product.modifier_group_count })"
                                >
                                    <NavIcon name="sliders" class="me-0.5 h-2.5 w-2.5" />{{ product.modifier_group_count }}
                                </span>
                                <span
                                    v-if="product.prep_time_minutes"
                                    class="inline-flex items-center rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-600 dark:bg-gray-800 dark:text-gray-400"
                                >
                                    <NavIcon name="clock" class="me-0.5 h-2.5 w-2.5" />{{ product.prep_time_minutes }}
                                </span>
                            </div>
                        </div>
                    </button>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 bg-gray-50 px-5 py-3 dark:border-gray-700 dark:bg-gray-900/50">
                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        class="text-xs text-gray-600 underline-offset-2 hover:underline disabled:opacity-40 dark:text-gray-400"
                        :disabled="selectableFiltered.length === 0"
                        @click="toggleSelectAll"
                    >
                        {{ allFilteredSelected ? $t('admin.menus.clear_selection') : $t('admin.menus.select_all_shown') }}
                    </button>
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        {{ $t('admin.menus.selected_count', { count: selectedIds.length }) }}
                    </span>
                </div>

                <div class="flex items-center gap-2">
                    <input
                        v-if="selectedIds.length === 1"
                        v-model="priceOverride"
                        type="number"
                        step="0.01"
                        min="0"
                        :placeholder="$t('admin.menus.price_override')"
                        class="focus-brand w-28 rounded-md border-gray-300 py-1.5 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                    />
                    <button
                        type="button"
                        class="rounded-md bg-gray-900 px-4 py-1.5 text-sm font-medium text-white hover:bg-gray-700 disabled:opacity-50 dark:bg-gray-100 dark:text-gray-900"
                        :disabled="selectedIds.length === 0 || processing"
                        @click="submit"
                    >
                        {{ $t('admin.menus.add_selected', { count: selectedIds.length }) }}
                    </button>
                </div>
            </div>
        </div>
    </Modal>
</template>
