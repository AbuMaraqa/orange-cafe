<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

defineProps({
    users: {
        type: Array,
        required: true,
    },
});

const { t } = useI18n();

function destroy(user) {
    if (confirm(`${user.name}: ` + t('common.are_you_sure'))) {
        router.delete(route('admin.users.destroy', user.id), { preserveScroll: true });
    }
}

const roleClasses = {
    admin: 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-400',
    waiter: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400',
    cashier: 'bg-teal-100 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400',
    kitchen: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400',
};
</script>

<template>
    <Head :title="$t('admin.users.title')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('admin.users.title') }}</template>

        <div class="mb-4 flex justify-end">
            <Link :href="route('admin.users.create')">
                <PrimaryButton>{{ $t('admin.users.create') }}</PrimaryButton>
            </Link>
        </div>

        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                <thead class="bg-gray-50 dark:bg-gray-900/60">
                    <tr class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-3 text-start">{{ $t('common.name') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('auth.email') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('admin.users.role') }}</th>
                        <th class="px-4 py-3 text-end">{{ $t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                    <tr v-for="user in users" :key="user.id">
                        <td class="whitespace-nowrap px-4 py-3 font-medium text-gray-900 dark:text-gray-100">{{ user.name }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-600 dark:text-gray-400" dir="ltr">{{ user.email }}</td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <span
                                v-if="user.role"
                                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="roleClasses[user.role] ?? roleClasses.waiter"
                            >
                                {{ $t(`admin.users.roles.${user.role}`) }}
                            </span>
                            <span v-else class="text-xs text-gray-400">—</span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-end text-sm">
                            <div class="flex items-center justify-end gap-3">
                                <Link :href="route('admin.users.edit', user.id)" class="text-gray-500 hover:text-gray-800 dark:hover:text-gray-200">
                                    {{ $t('common.edit') }}
                                </Link>
                                <button type="button" class="text-red-600 hover:text-red-800" @click="destroy(user)">
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
