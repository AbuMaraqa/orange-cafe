<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

defineProps({
    services: {
        type: Array,
        required: true,
    },
    languages: {
        type: Array,
        required: true,
    },
});

const { t } = useI18n();

function destroy(service) {
    if (confirm(`${service.name}: ` + t('common.are_you_sure'))) {
        router.delete(route('admin.services.destroy', service.id), { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="$t('nav.services')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('nav.services') }}</template>

        <p class="mb-4 text-sm text-gray-500">{{ $t('admin.services.hint') }}</p>

        <div class="mb-4 flex justify-end">
            <Link :href="route('admin.services.create')">
                <PrimaryButton>{{ $t('common.add') }}</PrimaryButton>
            </Link>
        </div>

        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                <thead class="bg-gray-50 dark:bg-gray-900/60">
                    <tr class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-3 text-start">{{ $t('common.name') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('common.status') }}</th>
                        <th class="px-4 py-3 text-end">{{ $t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                    <tr v-if="services.length === 0">
                        <td colspan="3" class="px-4 py-8 text-center text-sm text-gray-400">{{ $t('admin.services.none') }}</td>
                    </tr>
                    <tr v-for="service in services" :key="service.id">
                        <td class="whitespace-nowrap px-4 py-3 font-medium text-gray-900 dark:text-gray-100">
                            <div class="flex items-center gap-2">
                                <div
                                    class="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full text-base leading-none"
                                    :style="{ backgroundColor: service.color }"
                                >
                                    <img v-if="service.image_url" :src="service.image_url" class="h-full w-full object-cover" :alt="service.name" />
                                    <span v-else-if="service.icon" class="text-white">{{ service.icon }}</span>
                                </div>
                                {{ service.name }}
                            </div>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <span
                                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="service.is_active
                                    ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400'
                                    : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'"
                            >
                                {{ service.is_active ? $t('common.active') : $t('common.inactive') }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-end text-sm">
                            <div class="flex items-center justify-end gap-3">
                                <Link :href="route('admin.services.edit', service.id)" class="text-gray-500 hover:text-gray-800 dark:hover:text-gray-200">
                                    {{ $t('common.edit') }}
                                </Link>
                                <button type="button" class="text-red-600 hover:text-red-800" @click="destroy(service)">
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
