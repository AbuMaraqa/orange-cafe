<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

defineProps({
    menus: {
        type: Array,
        required: true,
    },
    languages: {
        type: Array,
        required: true,
    },
});

const { t } = useI18n();

const statusClasses = {
    draft: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
    published: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400',
    archived: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400',
};

function languageName(code, languages) {
    return languages.find((l) => l.code === code)?.native_name ?? code;
}

function destroy(menu) {
    if (confirm(`${menu.name}: ` + t('common.are_you_sure'))) {
        router.delete(route('admin.menus.destroy', menu.id), { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="$t('admin.menus.title')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('admin.menus.title') }}</template>

        <div class="mb-4 flex justify-end">
            <Link :href="route('admin.menus.create')">
                <PrimaryButton>{{ $t('admin.menus.add_menu') }}</PrimaryButton>
            </Link>
        </div>

        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                <thead class="bg-gray-50 dark:bg-gray-900/60">
                    <tr class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-3 text-start">{{ $t('common.name') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('admin.menus.publish_status') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('common.status') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('admin.menus.sections_count') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('admin.categories.missing_translations') }}</th>
                        <th class="px-4 py-3 text-end">{{ $t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                    <tr v-for="menu in menus" :key="menu.id">
                        <td class="whitespace-nowrap px-4 py-3 font-medium text-gray-900 dark:text-gray-100">
                            {{ menu.name }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClasses[menu.publish_status]">
                                {{ menu.publish_status_label }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <span
                                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="menu.is_active
                                    ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400'
                                    : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'"
                            >
                                {{ menu.is_active ? $t('common.active') : $t('common.inactive') }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm tabular-nums text-gray-600 dark:text-gray-400">
                            {{ menu.sections_count }} · {{ menu.items_count }} {{ $t('admin.menus.items') }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <span v-if="menu.missing_locales.length === 0" class="text-sm text-gray-400">—</span>
                            <span
                                v-for="code in menu.missing_locales"
                                :key="code"
                                class="me-1 inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/40 dark:text-amber-400"
                            >
                                {{ languageName(code, languages) }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-end text-sm">
                            <div class="flex items-center justify-end gap-3">
                                <Link :href="route('admin.menus.builder', menu.id)" class="text-gray-500 hover:text-gray-800 dark:hover:text-gray-200">
                                    {{ $t('admin.menus.builder') }}
                                </Link>
                                <Link :href="route('admin.menus.edit', menu.id)" class="text-gray-500 hover:text-gray-800 dark:hover:text-gray-200">
                                    {{ $t('common.edit') }}
                                </Link>
                                <button type="button" class="text-red-600 hover:text-red-800" @click="destroy(menu)">
                                    {{ $t('common.delete') }}
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AuthenticatedLayout>
</template>
