<script setup>
/**
 * One section of a menu, and — recursively — everything filed under it.
 *
 * Sections nest to arbitrary depth in the schema, and the customer menu
 * has always rendered them that way. This screen used to draw a single
 * flat level, so a sub-section created on the categories page simply did
 * not exist here.
 *
 * Actions are injected rather than emitted: bubbling nine events up
 * through an unbounded number of levels would mean every intermediate
 * section re-emitting handlers it has no interest in.
 */
import { computed, inject, ref, watch } from 'vue';
import NavIcon from '@/Components/NavIcon.vue';
import draggable from 'vuedraggable';
import { Link, usePage } from '@inertiajs/vue3';

const props = defineProps({
    section: { type: Object, required: true },
    depth: { type: Number, default: 0 },
});

const actions = inject('builderActions');
const page = usePage();

const collapsed = ref(false);

// Kept in a local copy so vuedraggable can mutate order optimistically;
// the server round-trip replaces it through the watcher below.
const items = ref([...props.section.items]);
const children = ref([...props.section.children]);

watch(() => props.section.items, (val) => { items.value = [...val]; });
watch(() => props.section.children, (val) => { children.value = [...val]; });

function formatPrice(cents) {
    return `${page.props.branding.currency_symbol}${(cents / 100).toFixed(2)}`;
}

// Nesting is shown with a left border and a small indent rather than a
// growing margin, so a deep tree stays readable on a narrow screen.
const isNested = computed(() => props.depth > 0);
</script>

<template>
    <div
        class="overflow-hidden rounded-lg border bg-white dark:bg-gray-900"
        :class="[
            isNested ? 'border-gray-200 dark:border-gray-800' : 'border-gray-200 dark:border-gray-800',
            section.is_active ? '' : 'opacity-70',
        ]"
    >
        <div
            class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-3 py-2.5 dark:border-gray-800"
            :class="isNested ? 'bg-gray-50/60 dark:bg-gray-900/40' : 'bg-gray-50 dark:bg-gray-900/60'"
        >
            <div class="flex min-w-0 items-center gap-2">
                <span class="section-drag-handle cursor-move text-gray-300 hover:text-gray-500">⠿</span>

                <button
                    type="button"
                    class="text-gray-400 transition hover:text-gray-600 dark:hover:text-gray-200"
                    :class="collapsed ? 'ltr:-rotate-90 rtl:rotate-90' : ''"
                    :aria-label="$t('admin.menus.toggle_section')"
                    @click="collapsed = !collapsed"
                >
                    <NavIcon name="chevronDown" class="h-4 w-4" />
                </button>

                <span
                    class="truncate font-medium text-gray-900 dark:text-gray-100"
                    :class="isNested ? 'text-sm' : ''"
                >
                    {{ section.name }}
                </span>

                <span class="shrink-0 text-xs text-gray-400">
                    {{ $t('admin.menus.items_count', { count: section.total_item_count }) }}
                </span>

                <span
                    v-if="section.station"
                    class="inline-flex shrink-0 items-center rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-600 dark:bg-gray-800 dark:text-gray-400"
                    :title="$t('admin.menus.station')"
                >
                    <NavIcon name="printer" class="me-0.5 h-2.5 w-2.5" />{{ section.station }}
                </span>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <button
                    type="button"
                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="section.is_active
                        ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400'
                        : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'"
                    :title="$t('admin.menus.section_active_hint')"
                    @click="actions.toggleSectionActive(section)"
                >
                    {{ section.is_active ? $t('common.active') : $t('common.inactive') }}
                </button>

                <button
                    type="button"
                    class="text-xs text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200"
                    @click="actions.addSubsection(section)"
                >
                    {{ $t('admin.menus.add_subsection') }}
                </button>

                <Link
                    :href="route('admin.categories.edit', section.id)"
                    class="text-xs text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
                >
                    {{ $t('common.edit') }}
                </Link>

                <button type="button" class="text-xs text-red-500 hover:text-red-700" @click="actions.removeSection(section)">
                    {{ $t('admin.menus.remove_section') }}
                </button>
            </div>
        </div>

        <div v-show="!collapsed">
            <draggable
                v-model="items"
                item-key="id"
                handle=".item-drag-handle"
                class="divide-y divide-gray-100 dark:divide-gray-800"
                @end="actions.reorderItems(section, items)"
            >
                <template #item="{ element: item }">
                    <div class="flex flex-wrap items-center gap-2 px-3 py-2 sm:flex-nowrap sm:gap-3">
                        <span class="item-drag-handle cursor-move text-gray-300 hover:text-gray-500">⠿</span>

                        <div class="h-9 w-9 shrink-0 overflow-hidden rounded-md bg-gray-100 dark:bg-gray-800">
                            <img v-if="item.image_url" :src="item.image_url" class="h-full w-full object-cover" />
                            <img
                                v-else-if="page.props.branding.logo_url"
                                :src="page.props.branding.logo_url"
                                class="h-full w-full object-contain p-1 opacity-70"
                            />
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-1.5">
                                <span class="truncate text-sm text-gray-800 dark:text-gray-200">{{ item.name }}</span>
                                <!--
                                    A line switched on inside a menu whose product is switched off
                                    globally never reaches a customer — the catalog filters on
                                    product.is_active. Without this badge the section looks correct
                                    and the item is simply missing from the menu.
                                -->
                                <span
                                    v-if="!item.product_is_active"
                                    class="shrink-0 rounded bg-red-100 px-1.5 py-0.5 text-[10px] text-red-700 dark:bg-red-900/40 dark:text-red-400"
                                    :title="$t('admin.menus.product_inactive_hint')"
                                >
                                    {{ $t('admin.menus.product_inactive') }}
                                </span>
                            </div>

                            <div v-if="item.tags.length || item.station || item.prep_time_minutes" class="mt-0.5 flex flex-wrap items-center gap-1">
                                <span
                                    v-for="tag in item.tags"
                                    :key="tag.id"
                                    class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-600 dark:bg-gray-800 dark:text-gray-400"
                                >
                                    <span v-if="tag.icon" class="me-0.5">{{ tag.icon }}</span>{{ tag.name }}
                                </span>
                                <span
                                    v-if="item.station"
                                    class="inline-flex items-center rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-600 dark:bg-gray-800 dark:text-gray-400"
                                    :title="$t('admin.menus.station')"
                                >
                                    <NavIcon name="printer" class="me-0.5 h-2.5 w-2.5" />{{ item.station }}
                                </span>
                                <span
                                    v-if="item.prep_time_minutes"
                                    class="inline-flex items-center rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-600 dark:bg-gray-800 dark:text-gray-400"
                                >
                                    <NavIcon name="clock" class="me-0.5 h-2.5 w-2.5" />{{ item.prep_time_minutes }}
                                </span>
                            </div>
                        </div>

                        <input
                            :value="item.price_cents === null ? '' : (item.price_cents / 100).toFixed(2)"
                            type="number"
                            step="0.01"
                            min="0"
                            :placeholder="(item.base_price_cents / 100).toFixed(2)"
                            :title="$t('admin.menus.price_override')"
                            class="focus-brand w-24 shrink-0 rounded-md border-gray-300 py-1 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                            @change="(e) => actions.updateItemPrice(item, e.target.value)"
                        />

                        <button
                            type="button"
                            class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="item.is_active
                                ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400'
                                : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'"
                            @click="actions.toggleItemFlag(item, 'is_active')"
                        >
                            {{ item.is_active ? $t('common.active') : $t('common.inactive') }}
                        </button>

                        <button
                            type="button"
                            class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="item.is_featured
                                ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400'
                                : 'bg-gray-100 text-gray-400 dark:bg-gray-800 dark:text-gray-500'"
                            @click="actions.toggleItemFlag(item, 'is_featured')"
                        >
                            <NavIcon name="star" class="me-1 -mt-0.5 inline h-3 w-3" />{{ $t('admin.menus.featured') }}
                        </button>

                        <button type="button" class="shrink-0 text-gray-300 hover:text-red-600" @click="actions.removeItem(item)">
                            <NavIcon name="close" class="h-4 w-4" />
                        </button>
                    </div>
                </template>
            </draggable>

            <div v-if="items.length === 0 && children.length === 0" class="px-3 py-3 text-sm text-gray-400">
                {{ $t('admin.menus.no_items') }}
            </div>

            <div v-if="children.length" class="space-y-2 border-t border-gray-100 p-2 ltr:pl-6 rtl:pr-6 dark:border-gray-800">
                <draggable
                    v-model="children"
                    item-key="id"
                    handle=".section-drag-handle"
                    class="space-y-2"
                    @end="actions.reorderSections(section.id, children)"
                >
                    <template #item="{ element: child }">
                        <MenuSection :section="child" :depth="depth + 1" />
                    </template>
                </draggable>
            </div>

            <div class="border-t border-gray-100 bg-gray-50/50 px-3 py-2 dark:border-gray-800 dark:bg-gray-900/30">
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-md bg-gray-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-gray-700 dark:bg-gray-100 dark:text-gray-900"
                    @click="actions.openPicker(section)"
                >
                    <NavIcon name="plus" class="h-4 w-4" />
                    {{ $t('admin.menus.add_products') }}
                </button>
            </div>
        </div>
    </div>
</template>
