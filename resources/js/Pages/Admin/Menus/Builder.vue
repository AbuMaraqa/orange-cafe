<script setup>
import { computed, provide, ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import NavIcon from '@/Components/NavIcon.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import MenuSection from './Partials/MenuSection.vue';
import ProductPicker from './Partials/ProductPicker.vue';
import { Head, Link, router } from '@inertiajs/vue3';
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
    tags: {
        type: Array,
        default: () => [],
    },
});

const { t } = useI18n();

function clone(value) {
    return JSON.parse(JSON.stringify(value));
}

const sectionsList = ref(clone(props.sections));
watch(() => props.sections, (val) => { sectionsList.value = clone(val); });

/**
 * Sections are a tree now, so "how many products are on this menu" is a
 * walk rather than a sum of one level.
 */
const totals = computed(() => {
    let sections = 0;
    let items = 0;

    const walk = (list) => {
        list.forEach((section) => {
            sections += 1;
            items += section.items.length;
            walk(section.children);
        });
    };

    walk(sectionsList.value);

    return { sections, items };
});

/* ---------------------------------------------------------------- sections */

const sectionForm = ref({ show: false, parent: null, name: '', processing: false });

function slugify(value) {
    return value.trim().toLowerCase().replace(/\s+/g, '-').replace(/[^\p{L}\p{N}_-]/gu, '');
}

function openSectionForm(parent = null) {
    sectionForm.value = { show: true, parent, name: '', processing: false };
}

function submitSectionForm() {
    const { name, parent } = sectionForm.value;
    if (!name.trim() || !props.defaultLocale) return;

    sectionForm.value.processing = true;

    router.post(route('admin.menus.sections.store', props.menu.id), {
        // Slugs are global across categories, so two menus each wanting a
        // "مشروبات" section would collide on the second one. The suffix
        // keeps the name the administrator typed while staying unique.
        slug: `${slugify(name) || 'section'}-${Date.now().toString(36)}`,
        parent_id: parent?.id ?? null,
        translations: { [props.defaultLocale.code]: { name } },
    }, {
        preserveScroll: true,
        onSuccess: () => { sectionForm.value.show = false; },
        onFinish: () => { sectionForm.value.processing = false; },
    });
}

function removeSection(section) {
    const warning = section.total_item_count > 0
        ? t('admin.menus.remove_section_with_items', { count: section.total_item_count })
        : t('common.are_you_sure');

    if (!confirm(`${section.name}: ${warning}`)) return;

    router.delete(route('admin.menus.sections.destroy', section.id), { preserveScroll: true });
}

function toggleSectionActive(section) {
    router.patch(route('admin.menus.sections.update', section.id), {
        is_active: !section.is_active,
    }, { preserveScroll: true });
}

function reorderSections(parentId, list) {
    router.patch(route('admin.menus.sections.reorder', props.menu.id), {
        parent_id: parentId,
        ids: list.map((s) => s.id),
    }, { preserveScroll: true, preserveState: true });
}

/* ------------------------------------------------------------------- items */

const picker = ref({ show: false, section: null, processing: false });

function openPicker(section) {
    picker.value = { show: true, section, processing: false };
}

function addProducts(payload) {
    picker.value.processing = true;

    router.post(route('admin.menus.items.store', picker.value.section.id), payload, {
        preserveScroll: true,
        onSuccess: () => { picker.value.show = false; },
        onFinish: () => { picker.value.processing = false; },
    });
}

function removeItem(item) {
    if (!confirm(`${item.name}: ` + t('common.are_you_sure'))) return;
    router.delete(route('admin.menus.items.destroy', item.id), { preserveScroll: true });
}

function updateItemPrice(item, value) {
    router.patch(route('admin.menus.items.update', item.id), {
        price_cents: value === '' || value === null ? null : Math.round(Number(value) * 100),
    }, { preserveScroll: true, preserveState: true });
}

function toggleItemFlag(item, field) {
    router.patch(route('admin.menus.items.update', item.id), {
        [field]: !item[field],
    }, { preserveScroll: true, preserveState: true });
}

function reorderItems(section, list) {
    router.patch(route('admin.menus.items.reorder', section.id), {
        ids: list.map((i) => i.id),
    }, { preserveScroll: true, preserveState: true });
}

// Injected rather than emitted so a section nested five levels deep talks
// to this page directly instead of through five re-emits.
provide('builderActions', {
    openPicker,
    addSubsection: (section) => openSectionForm(section),
    removeSection,
    toggleSectionActive,
    reorderSections,
    removeItem,
    updateItemPrice,
    toggleItemFlag,
    reorderItems,
});
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

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <div class="min-w-0">
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $t('admin.menus.builder_hint') }}</p>
                <p class="mt-1 text-xs text-gray-400">
                    {{ $t('admin.menus.builder_totals', { sections: totals.sections, items: totals.items }) }}
                </p>
            </div>
            <Link :href="route('admin.menus.edit', menu.id)" class="shrink-0 text-sm text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">
                {{ $t('admin.menus.edit_details') }}
            </Link>
        </div>

        <draggable
            v-model="sectionsList"
            item-key="id"
            handle=".section-drag-handle"
            class="space-y-3"
            @end="reorderSections(null, sectionsList)"
        >
            <template #item="{ element: section }">
                <MenuSection :section="section" :depth="0" />
            </template>
        </draggable>

        <div v-if="sectionsList.length === 0" class="rounded-lg border border-dashed border-gray-300 p-8 text-center text-sm text-gray-400 dark:border-gray-700">
            {{ $t('admin.menus.no_sections') }}
        </div>

        <div class="mt-4">
            <button
                type="button"
                class="inline-flex items-center gap-1.5 rounded-md border border-dashed border-gray-300 px-4 py-2 text-sm font-medium text-gray-600 hover:border-gray-400 hover:text-gray-900 dark:border-gray-700 dark:text-gray-300 dark:hover:text-white"
                @click="openSectionForm(null)"
            >
                <NavIcon name="plus" class="h-4 w-4" />
                {{ $t('admin.menus.add_section') }}
            </button>
        </div>

        <ProductPicker
            :show="picker.show"
            :section="picker.section"
            :products="availableProducts"
            :tags="tags"
            :processing="picker.processing"
            @close="picker.show = false"
            @add="addProducts"
        />

        <Modal :show="sectionForm.show" max-width="md" @close="sectionForm.show = false">
            <div class="p-5">
                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                    {{ sectionForm.parent
                        ? $t('admin.menus.add_subsection_to', { section: sectionForm.parent.name })
                        : $t('admin.menus.add_section') }}
                </h2>
                <p v-if="defaultLocale" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ $t('admin.menus.section_name_locale_hint', { language: defaultLocale.native_name }) }}
                </p>

                <input
                    v-model="sectionForm.name"
                    type="text"
                    :placeholder="$t('admin.menus.new_section_placeholder')"
                    class="focus-brand mt-3 w-full rounded-md border-gray-300 py-1.5 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                    @keyup.enter="submitSectionForm"
                />

                <div class="mt-4 flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-md px-3 py-1.5 text-sm text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white"
                        @click="sectionForm.show = false"
                    >
                        {{ $t('common.cancel') }}
                    </button>
                    <button
                        type="button"
                        class="rounded-md bg-gray-900 px-4 py-1.5 text-sm font-medium text-white hover:bg-gray-700 disabled:opacity-50 dark:bg-gray-100 dark:text-gray-900"
                        :disabled="sectionForm.processing || !sectionForm.name.trim()"
                        @click="submitSectionForm"
                    >
                        {{ $t('common.add') }}
                    </button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
