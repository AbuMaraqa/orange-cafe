<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

defineProps({
    groups: {
        type: Array,
        required: true,
    },
    languages: {
        type: Array,
        required: true,
    },
});

const { t } = useI18n();

function destroy(group) {
    if (confirm(`${group.name}: ` + t('common.are_you_sure'))) {
        router.delete(route('admin.modifier-groups.destroy', group.id), { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="$t('nav.modifier_groups')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('nav.modifier_groups') }}</template>

        <div class="mb-4 flex justify-end">
            <Link :href="route('admin.modifier-groups.create')">
                <PrimaryButton>{{ $t('common.add') }}</PrimaryButton>
            </Link>
        </div>

        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                <thead class="bg-gray-50 dark:bg-gray-900/60">
                    <tr class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-3 text-start">{{ $t('common.name') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('admin.modifier_groups.rule') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('admin.modifier_groups.options') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('admin.modifier_groups.used_by') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('common.status') }}</th>
                        <th class="px-4 py-3 text-end">{{ $t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                    <tr v-for="group in groups" :key="group.id">
                        <td class="whitespace-nowrap px-4 py-3 font-medium text-gray-900 dark:text-gray-100">
                            {{ group.name }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                            <span v-if="group.is_required">{{ $t('admin.modifier_groups.required') }}</span>
                            <span v-else>{{ $t('admin.modifier_groups.optional') }}</span>
                            · {{ group.min_select }}–{{ group.max_select ?? '∞' }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                            {{ group.modifiers_count }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                            <span
                                v-if="group.products_count > 0"
                                class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300"
                                :title="$t('admin.modifier_groups.used_by_count', { count: group.products_count })"
                            >{{ group.products_count }}</span>
                            <span v-else class="text-gray-400">—</span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <span
                                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="group.is_active
                                    ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400'
                                    : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'"
                            >
                                {{ group.is_active ? $t('common.active') : $t('common.inactive') }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-end text-sm">
                            <div class="flex items-center justify-end gap-3">
                                <Link :href="route('admin.modifier-groups.edit', group.id)" class="text-gray-500 hover:text-gray-800 dark:hover:text-gray-200">
                                    {{ $t('common.edit') }}
                                </Link>
                                <button type="button" class="text-red-600 hover:text-red-800" @click="destroy(group)">
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
