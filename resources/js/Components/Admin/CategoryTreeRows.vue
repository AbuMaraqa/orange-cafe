<script setup>
import { Link, usePage } from '@inertiajs/vue3';

defineOptions({ name: 'CategoryTreeRows' });

defineProps({
    categories: {
        type: Array,
        required: true,
    },
    languages: {
        type: Array,
        required: true,
    },
    depth: {
        type: Number,
        default: 0,
    },
});

const page = usePage();

const emit = defineEmits(['delete']);

function languageName(code, languages) {
    return languages.find((l) => l.code === code)?.native_name ?? code;
}
</script>

<template>
    <template v-for="category in categories" :key="category.id">
        <tr :class="depth > 0 ? 'bg-gray-50/60 dark:bg-gray-900/30' : ''">
            <td
                class="whitespace-nowrap py-3 text-sm text-gray-900 dark:text-gray-100"
                :class="depth > 0 ? 'font-normal' : 'px-4 font-medium'"
                :style="depth > 0 ? { paddingInlineStart: `${1 + depth * 1.5}rem`, paddingInlineEnd: '1rem' } : null"
            >
                <span v-if="depth > 0" class="text-gray-400">└</span>
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
        <CategoryTreeRows
            v-if="category.children?.length"
            :categories="category.children"
            :languages="languages"
            :depth="depth + 1"
            @delete="(c) => emit('delete', c)"
        />
    </template>
</template>
