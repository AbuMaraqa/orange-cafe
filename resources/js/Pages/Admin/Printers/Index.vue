<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

defineProps({
    printers: {
        type: Array,
        required: true,
    },
});

const { t } = useI18n();

function destroy(printer) {
    if (confirm(`${printer.name}: ` + t('common.are_you_sure'))) {
        router.delete(route('admin.printers.destroy', printer.id), { preserveScroll: true });
    }
}

function test(printer) {
    router.post(route('admin.printers.test', printer.id), {}, { preserveScroll: true });
}

const connectionClasses = {
    online: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400',
    offline: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400',
    unknown: 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400',
};

function checkedAgo(iso) {
    if (!iso) return null;

    const minutes = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000));

    return minutes < 1 ? t('staff.just_now') : t('staff.minutes_ago', { minutes });
}
</script>

<template>
    <Head :title="$t('nav.printers')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('nav.printers') }}</template>

        <div class="mb-4 flex justify-end">
            <Link :href="route('admin.printers.create')">
                <PrimaryButton>{{ $t('common.add') }}</PrimaryButton>
            </Link>
        </div>

        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                <thead class="bg-gray-50 dark:bg-gray-900/60">
                    <tr class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-3 text-start">{{ $t('common.name') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('printers.station') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('printers.address') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('common.status') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('printers.connection') }}</th>
                        <th class="px-4 py-3 text-end">{{ $t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                    <tr v-for="printer in printers" :key="printer.id">
                        <td class="whitespace-nowrap px-4 py-3 font-medium text-gray-900 dark:text-gray-100">{{ printer.name }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{{ printer.station }}</td>
                        <td class="whitespace-nowrap px-4 py-3 font-mono text-sm text-gray-600 dark:text-gray-400">{{ printer.ip_address }}:{{ printer.port }}</td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <span
                                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="printer.is_active
                                    ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400'
                                    : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'"
                            >
                                {{ printer.is_active ? $t('common.active') : $t('common.inactive') }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium" :class="connectionClasses[printer.status] ?? connectionClasses.unknown">
                                <span class="h-1.5 w-1.5 rounded-full" :class="{
                                    'bg-green-500': printer.status === 'online',
                                    'bg-red-500': printer.status === 'offline',
                                    'bg-gray-400': !printer.status || printer.status === 'unknown',
                                }"></span>
                                {{ $t(`printers.connection_${printer.status ?? 'unknown'}`) }}
                            </span>
                            <span v-if="printer.status_checked_at" class="ms-1.5 text-xs text-gray-400">{{ checkedAgo(printer.status_checked_at) }}</span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-end text-sm">
                            <div class="flex items-center justify-end gap-3">
                                <button type="button" class="text-gray-500 hover:text-gray-800 dark:hover:text-gray-200" @click="test(printer)">
                                    {{ $t('printers.test') }}
                                </button>
                                <Link :href="route('admin.printers.edit', printer.id)" class="text-gray-500 hover:text-gray-800 dark:hover:text-gray-200">
                                    {{ $t('common.edit') }}
                                </Link>
                                <button type="button" class="text-red-600 hover:text-red-800" @click="destroy(printer)">
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
