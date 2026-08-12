<script setup>
import { reactive, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import draggable from 'vuedraggable';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    menu: {
        type: Object,
        required: true,
    },
    defaultLocale: {
        type: Object,
        default: null,
    },
    sections: {
        type: Array,
        required: true,
    },
    availableProducts: {
        type: Array,
        required: true,
    },
});

const { t } = useI18n();
const page = usePage();

function formatPrice(cents) {
    return `${page.props.branding.currency_symbol}${(cents / 100).toFixed(2)}`;
}

function clone(value) {
    return JSON.parse(JSON.stringify(value));
}

const sectionsList = ref(clone(props.sections));
watch(() => props.sections, (val) => { sectionsList.value = clone(val); });

const newSectionName = ref('');
const addingSection = ref(false);

function slugify(value) {
    return value.trim().toLowerCase().replace(/\s+/g, '-').replace(/[^\p{L}\p{N}_-]/gu, '');
}

function addSection() {
    if (!newSectionName.value.trim() || !props.defaultLocale) return;
    addingSection.value = true;
    router.post(route('admin.menus.sections.store', props.menu.id), {
        slug: slugify(newSectionName.value) || `section-${Date.now()}`,
        translations: { [props.defaultLocale.code]: { name: newSectionName.value } },
    }, {
        preserveScroll: true,
        onSuccess: () => { newSectionName.value = ''; },
        onFinish: () => { addingSection.value = false; },
    });
}

function removeSection(section) {
    if (!confirm(`${section.name}: ` + t('common.are_you_sure'))) return;
    router.delete(route('admin.menus.sections.destroy', section.id), { preserveScroll: true });
}

function onSectionsReorder() {
    router.patch(route('admin.menus.sections.reorder', props.menu.id), {
        ids: sectionsList.value.map((s) => s.id),
    }, { preserveScroll: true, preserveState: true });
}

function onItemsReorder(section) {
    router.patch(route('admin.menus.items.reorder', section.id), {
        ids: section.items.map((i) => i.id),
    }, { preserveScroll: true, preserveState: true });
}

const newItemState = reactive({});

function ensureItemState(sectionId) {
    if (!newItemState[sectionId]) {
        newItemState[sectionId] = { product_id: '', price_cents: '' };
    }
    return newItemState[sectionId];
}

function addItem(section) {
    const state = ensureItemState(section.id);
    if (!state.product_id) return;

    router.post(route('admin.menus.items.store', section.id), {
        product_id: state.product_id,
        price_cents: state.price_cents === '' ? null : Math.round(Number(state.price_cents) * 100),
    }, {
        preserveScroll: true,
        onSuccess: () => { newItemState[section.id] = { product_id: '', price_cents: '' }; },
    });
}

function removeItem(item) {
    if (!confirm(`${item.name}: ` + t('common.are_you_sure'))) return;
    router.delete(route('admin.menus.items.destroy', item.id), { preserveScroll: true });
}

function updateItemPrice(item) {
    router.patch(route('admin.menus.items.update', item.id), {
        price_cents: item.price_cents === '' || item.price_cents === null ? null : Math.round(Number(item.price_cents) * 100),
    }, { preserveScroll: true, preserveState: true });
}

function toggleItemFlag(item, field) {
    router.patch(route('admin.menus.items.update', item.id), {
        [field]: !item[field],
    }, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <Head :title="`${$t('admin.menus.builder')}: ${menu.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('admin.menus.index')" class="text-sm text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                    {{ $t('admin.menus.title') }}
                </Link>
                <span class="text-gray-300">/</span>
                <span>{{ menu.name }}</span>
            </div>
        </template>

        <div class="mb-4 flex items-center justify-between rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $t('admin.menus.builder_hint') }}</p>
            <Link :href="route('admin.menus.edit', menu.id)" class="shrink-0 text-sm text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">
                {{ $t('admin.menus.edit_details') }}
            </Link>
        </div>

        <draggable
            v-model="sectionsList"
            item-key="id"
            handle=".section-drag-handle"
            class="space-y-4"
            @end="onSectionsReorder"
        >
            <template #item="{ element: section }">
                <div class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                    <div class="flex items-center justify-between border-b border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-800 dark:bg-gray-900/60">
                        <div class="flex items-center gap-2">
                            <span class="section-drag-handle cursor-move text-gray-300 hover:text-gray-500">⠿</span>
                            <span class="font-medium text-gray-900 dark:text-gray-100">{{ section.name }}</span>
                            <Link :href="route('admin.categories.edit', section.id)" class="text-xs text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">
                                {{ $t('common.edit') }}
                            </Link>
                        </div>
                        <button type="button" class="text-xs text-red-500 hover:text-red-700" @click="removeSection(section)">
                            {{ $t('admin.menus.remove_section') }}
                        </button>
                    </div>

                    <draggable
                        v-model="section.items"
                        item-key="id"
                        handle=".item-drag-handle"
                        class="divide-y divide-gray-100 dark:divide-gray-800"
                        @end="onItemsReorder(section)"
                    >
                        <template #item="{ element: item }">
                            <div class="flex items-center gap-3 px-4 py-2.5">
                                <span class="item-drag-handle cursor-move text-gray-300 hover:text-gray-500">⠿</span>
                                <div class="h-9 w-9 shrink-0 overflow-hidden rounded-md bg-gray-100 dark:bg-gray-800">
                                    <img v-if="item.image_url" :src="item.image_url" class="h-full w-full object-cover" />
                                </div>
                                <span class="min-w-0 flex-1 truncate text-sm text-gray-800 dark:text-gray-200">{{ item.name }}</span>

                                <input
                                    :value="item.price_cents === null ? '' : (item.price_cents / 100).toFixed(2)"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    :placeholder="(item.base_price_cents / 100).toFixed(2)"
                                    class="w-24 rounded-md border-gray-300 py-1 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                                    @change="(e) => { item.price_cents = e.target.value; updateItemPrice(item); }"
                                />

                                <button
                                    type="button"
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="item.is_active
                                        ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400'
                                        : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'"
                                    @click="toggleItemFlag(item, 'is_active')"
                                >
                                    {{ item.is_active ? $t('common.active') : $t('common.inactive') }}
                                </button>

                                <button
                                    type="button"
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="item.is_featured
                                        ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400'
                                        : 'bg-gray-100 text-gray-400 dark:bg-gray-800 dark:text-gray-500'"
                                    @click="toggleItemFlag(item, 'is_featured')"
                                >
                                    ★ {{ $t('admin.menus.featured') }}
                                </button>

                                <button type="button" class="text-gray-300 hover:text-red-600" @click="removeItem(item)">✕</button>
                            </div>
                        </template>
                    </draggable>

                    <div v-if="section.items.length === 0" class="px-4 py-3 text-sm text-gray-400">
                        {{ $t('admin.menus.no_items') }}
                    </div>

                    <div class="flex items-center gap-2 border-t border-gray-100 bg-gray-50/50 px-4 py-2.5 dark:border-gray-800 dark:bg-gray-900/30">
                        <select
                            v-model="ensureItemState(section.id).product_id"
                            class="min-w-0 flex-1 rounded-md border-gray-300 py-1.5 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                        >
                            <option value="">{{ $t('admin.menus.pick_product') }}</option>
                            <option v-for="product in availableProducts" :key="product.id" :value="product.id">
                                {{ product.name }} — {{ formatPrice(product.price_cents) }}
                            </option>
                        </select>
                        <input
                            v-model="ensureItemState(section.id).price_cents"
                            type="number"
                            step="0.01"
                            min="0"
                            :placeholder="$t('admin.menus.price_override')"
                            class="w-28 rounded-md border-gray-300 py-1.5 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                        />
                        <button
                            type="button"
                            class="shrink-0 rounded-md bg-gray-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-gray-700 disabled:opacity-50 dark:bg-gray-100 dark:text-gray-900"
                            :disabled="!ensureItemState(section.id).product_id"
                            @click="addItem(section)"
                        >
                            {{ $t('common.add') }}
                        </button>
                    </div>
                </div>
            </template>
        </draggable>

        <div class="mt-4 flex items-center gap-2 rounded-lg border border-dashed border-gray-300 p-4 dark:border-gray-700">
            <input
                v-model="newSectionName"
                type="text"
                :placeholder="$t('admin.menus.new_section_placeholder')"
                class="min-w-0 flex-1 rounded-md border-gray-300 py-1.5 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                @keyup.enter="addSection"
            />
            <button
                type="button"
                class="shrink-0 rounded-md bg-gray-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-gray-700 disabled:opacity-50 dark:bg-gray-100 dark:text-gray-900"
                :disabled="addingSection || !newSectionName.trim()"
                @click="addSection"
            >
                {{ $t('admin.menus.add_section') }}
            </button>
        </div>
    </AuthenticatedLayout>
</template>
