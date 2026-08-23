<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import draggable from 'vuedraggable';

defineOptions({ name: 'CategoryTreeRows' });

const props = defineProps({
    categories: {
        type: Array,
        required: true,
    },
    languages: {
        type: Array,
        required: true,
    },
});

const page = usePage();

const emit = defineEmits(['delete', 'reorder']);

// The table is one flat list of <tr>, because that is the only shape a
// <tbody> can be dragged as — nesting a draggable tbody inside another is
// not valid HTML and browsers silently hoist it out. So the tree is
// flattened for display, each row remembering how deep it sits and who its
// parent is, and re-assembled after every drop.
const tree = ref(clone(props.categories));

watch(() => props.categories, (categories) => {
    tree.value = clone(categories);
    rows.value = flatten(tree.value);
}, { deep: true });

function clone(nodes) {
    return JSON.parse(JSON.stringify(nodes));
}

function flatten(nodes, depth = 0, parentId = null, out = []) {
    for (const node of nodes) {
        out.push({ ...node, depth, parent_id: parentId });

        if (node.children?.length) {
            flatten(node.children, depth + 1, node.id, out);
        }
    }

    return out;
}

const rows = ref(flatten(tree.value));

const isDraggable = computed(() => rows.value.length > 1);

/**
 * Refuses any drop that would land a row outside its own sibling group.
 *
 * Reordering and re-parenting are different operations with different
 * consequences — moving "لاتيه" from under "قهوة ساخنة" to under "عصائر"
 * changes where customers find it, not just the order — and a drag gesture
 * cannot say which one was meant. Only the first is offered here; changing
 * a parent stays an explicit choice in the edit form.
 */
function canDrop(event) {
    return event.draggedContext.element.parent_id === event.relatedContext.element?.parent_id;
}

/**
 * draggable has moved exactly one row in the flat list, leaving that row's
 * children behind where they were. Re-sorting each sibling array by the
 * dropped position and re-flattening puts the descendants back underneath
 * the parent they belong to, so the table matches what the server is about
 * to be told.
 */
function onDrop() {
    const dropped = rows.value.map((row) => row.id);

    sortTree(tree.value, dropped);
    rows.value = flatten(tree.value);

    emit('reorder', rows.value.map((row) => row.id));
}

function sortTree(nodes, dropped) {
    nodes.sort((a, b) => dropped.indexOf(a.id) - dropped.indexOf(b.id));

    for (const node of nodes) {
        if (node.children?.length) {
            sortTree(node.children, dropped);
        }
    }
}

function languageName(code, languages) {
    return languages.find((l) => l.code === code)?.native_name ?? code;
}
</script>

<template>
    <draggable
        v-model="rows"
        tag="tbody"
        item-key="id"
        handle=".category-drag-handle"
        class="divide-y divide-gray-200 dark:divide-gray-800"
        :move="canDrop"
        @end="onDrop"
    >
        <template #item="{ element: category }">
        <tr :class="category.depth > 0 ? 'bg-gray-50/60 dark:bg-gray-900/30' : ''">
            <!-- A handle rather than the whole row: the row is mostly links,
                 and a draggable row swallows the click that opens them. -->
            <td class="w-8 px-2 py-3 text-center">
                <span
                    v-if="isDraggable"
                    class="category-drag-handle cursor-move select-none text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                    :title="$t('admin.categories.drag_to_reorder')"
                >⋮⋮</span>
            </td>
            <td
                class="whitespace-nowrap py-3 text-sm text-gray-900 dark:text-gray-100"
                :class="category.depth > 0 ? 'font-normal' : 'px-4 font-medium'"
                :style="category.depth > 0 ? { paddingInlineStart: `${1 + category.depth * 1.5}rem`, paddingInlineEnd: '1rem' } : null"
            >
                <span v-if="category.depth > 0" class="text-gray-400">└</span>
                <span class="inline-flex items-center gap-2">
                    <img v-if="category.image_url" :src="category.image_url" class="h-6 w-6 shrink-0 rounded-full object-cover" :alt="category.name" />
                    <img
                        v-else-if="page.props.branding.logo_url"
                        :src="page.props.branding.logo_url"
                        class="h-6 w-6 shrink-0 rounded-full bg-gray-100 object-contain p-0.5 dark:bg-gray-800"
                        :alt="category.name"
                    />
                    {{ category.name }}
                </span>
            </td>
            <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                {{ category.menu_name ?? '—' }}
            </td>
            <td class="whitespace-nowrap px-4 py-3 font-mono text-sm text-gray-600 dark:text-gray-400">
                {{ category.slug }}
            </td>
            <td class="whitespace-nowrap px-4 py-3">
                <span
                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="category.is_active
                        ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400'
                        : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'"
                >
                    {{ category.is_active ? $t('common.active') : $t('common.inactive') }}
                </span>
            </td>
            <td class="whitespace-nowrap px-4 py-3">
                <span v-if="category.missing_locales.length === 0" class="text-sm text-gray-400">—</span>
                <span
                    v-for="code in category.missing_locales"
                    :key="code"
                    class="me-1 inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/40 dark:text-amber-400"
                >
                    {{ languageName(code, languages) }}
                </span>
            </td>
            <td class="whitespace-nowrap px-4 py-3 text-end text-sm">
                <div class="flex items-center justify-end gap-3">
                    <Link :href="route('admin.categories.edit', category.id)" class="text-gray-500 hover:text-gray-800 dark:hover:text-gray-200">
                        {{ $t('common.edit') }}
                    </Link>
                    <button type="button" class="text-red-600 hover:text-red-800" @click="emit('delete', category)">
                        {{ $t('common.delete') }}
                    </button>
                </div>
            </td>
        </tr>
        </template>
    </draggable>
</template>
