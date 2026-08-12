<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

defineProps({
    categories: {
        type: Array,
        required: true,
    },
    languages: {
        type: Array,
        required: true,
    },
});

const { t } = useI18n();

function destroy(category) {
    if (confirm(`${category.name}: ` + t('common.are_you_sure'))) {
        router.delete(route('admin.categories.destroy', category.id), { preserveScroll: true });
    }
}

function languageName(code, languages) {
    return languages.find((l) => l.code === code)?.native_name ?? code;
}
</script>

<template>
    <Head :title="$t('admin.categories.title')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('admin.categories.title') }}</template>

        <div class="mb-4 flex justify-end">
            <Link :href="route('admin.categories.create')">
                <PrimaryButton>{{ $t('admin.categories.add_category') }}</PrimaryButton>
            </Link>
        </div>

        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                <thead class="bg-gray-50 dark:bg-gray-900/60">
                    <tr class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-3 text-start">{{ $t('common.name') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('admin.categories.menu') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('admin.categories.slug') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('common.status') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('admin.categories.missing_translations') }}</th>
                        <th class="px-4 py-3 text-end">{{ $t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                    <template v-for="category in categories" :key="category.id">
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3 font-medium text-gray-900 dark:text-gray-100">
                                {{ category.name }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                                {{ category.menu_name }}
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
                                    <button type="button" class="text-red-600 hover:text-red-800" @click="destroy(category)">
                                        {{ $t('common.delete') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-for="child in category.children" :key="`child-${child.id}`" class="bg-gray-50/60 dark:bg-gray-900/30">
                            <td class="whitespace-nowrap px-4 py-2.5 ps-10 text-sm text-gray-700 dark:text-gray-300">
                                <span class="text-gray-400">└</span> {{ child.name }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5 font-mono text-sm text-gray-500 dark:text-gray-400">
                                {{ child.slug }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5">
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="child.is_active
                                        ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400'
                                        : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'"
                                >
                                    {{ child.is_active ? $t('common.active') : $t('common.inactive') }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5">
                                <span v-if="child.missing_locales.length === 0" class="text-sm text-gray-400">—</span>
                                <span
                                    v-for="code in child.missing_locales"
                                    :key="code"
                                    class="me-1 inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/40 dark:text-amber-400"
                                >
                                    {{ languageName(code, languages) }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-end text-sm">
                                <div class="flex items-center justify-end gap-3">
                                    <Link :href="route('admin.categories.edit', child.id)" class="text-gray-500 hover:text-gray-800 dark:hover:text-gray-200">
                                        {{ $t('common.edit') }}
                                    </Link>
                                    <button type="button" class="text-red-600 hover:text-red-800" @click="destroy(child)">
                                        {{ $t('common.delete') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </AuthenticatedLayout>
</template>
