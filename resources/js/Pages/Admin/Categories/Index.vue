<script setup>
import CategoryTreeRows from '@/Components/Admin/CategoryTreeRows.vue';
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
                    <CategoryTreeRows :categories="categories" :languages="languages" @delete="destroy" />
                </tbody>
            </table>
        </div>
    </AuthenticatedLayout>
</template>
