<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    languages: {
        type: Array,
        required: true,
    },
    translationStatus: {
        type: Object,
        required: true,
    },
});

function toggleActive(language) {
    router.patch(route('admin.languages.activate', language.id), {}, { preserveScroll: true });
}

function makeDefault(language) {
    router.patch(route('admin.languages.default', language.id), {}, { preserveScroll: true });
}

function destroy(language) {
    if (confirm(`${language.native_name}: ` + t('common.are_you_sure'))) {
        router.delete(route('admin.languages.destroy', language.id), { preserveScroll: true });
    }
}

function move(language, direction) {
    const index = props.languages.findIndex((l) => l.id === language.id);
    const swapWith = props.languages[index + direction];
    if (!swapWith) return;

    const order = props.languages.map((l) => l.id);
    [order[index], order[index + direction]] = [order[index + direction], order[index]];

    router.patch(route('admin.languages.reorder'), { order }, { preserveScroll: true });
}

function coverage(code) {
    const models = Object.values(props.translationStatus);
    if (models.length === 0) return null;

    const total = models.reduce((sum, m) => sum + m.total, 0);
    const translated = models.reduce((sum, m) => sum + (m.byLocale[code] ?? 0), 0);

    return total === 0 ? 100 : Math.round((translated / total) * 100);
}
</script>

<template>
    <Head :title="$t('admin.languages.title')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('admin.languages.title') }}</template>

        <div class="mb-4 flex justify-end">
            <Link :href="route('admin.languages.create')">
                <PrimaryButton>{{ $t('admin.languages.add_language') }}</PrimaryButton>
            </Link>
        </div>

        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                        <thead class="bg-gray-50 dark:bg-gray-900/60">
                            <tr class="text-start text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                <th class="px-4 py-3 text-start"></th>
                                <th class="px-4 py-3 text-start">{{ $t('admin.languages.native_name') }}</th>
                                <th class="px-4 py-3 text-start">{{ $t('admin.languages.code') }}</th>
                                <th class="px-4 py-3 text-start">{{ $t('admin.languages.direction') }}</th>
                                <th class="px-4 py-3 text-start">{{ $t('admin.languages.translation_coverage') }}</th>
                                <th class="px-4 py-3 text-start">{{ $t('common.status') }}</th>
                                <th class="px-4 py-3 text-end">{{ $t('common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <tr v-for="(language, index) in languages" :key="language.id">
                                <td class="whitespace-nowrap px-4 py-3">
                                    <div class="flex flex-col gap-0.5 text-gray-400">
                                        <button type="button" :disabled="index === 0" class="disabled:opacity-25" @click="move(language, -1)">▲</button>
                                        <button type="button" :disabled="index === languages.length - 1" class="disabled:opacity-25" @click="move(language, 1)">▼</button>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <div class="font-medium text-gray-900 dark:text-gray-100">{{ language.native_name }}</div>
                                    <div class="text-sm text-gray-500">{{ language.name }}</div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 font-mono text-sm text-gray-600 dark:text-gray-400">
                                    {{ language.code }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                                    {{ language.direction.toUpperCase() }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                                    <span v-if="coverage(language.code) !== null">{{ coverage(language.code) }}%</span>
                                    <span v-else>—</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <span
                                        v-if="language.is_default"
                                        class="inline-flex rounded-full bg-gray-900 px-2 py-0.5 text-xs font-medium text-white dark:bg-gray-100 dark:text-gray-900"
                                    >
                                        {{ $t('common.default') }}
                                    </span>
                                    <span
                                        v-else-if="language.is_active"
                                        class="inline-flex rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700 dark:bg-green-900/40 dark:text-green-400"
                                    >
                                        {{ $t('common.active') }}
                                    </span>
                                    <span
                                        v-else
                                        class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500 dark:bg-gray-700 dark:text-gray-400"
                                    >
                                        {{ $t('common.inactive') }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-end text-sm">
                                    <div class="flex items-center justify-end gap-3">
                                        <button
                                            v-if="!language.is_default"
                                            type="button"
                                            class="text-gray-500 hover:text-gray-800 dark:hover:text-gray-200"
                                            @click="makeDefault(language)"
                                        >
                                            {{ $t('admin.languages.set_default') }}
                                        </button>
                                        <button
                                            type="button"
                                            class="text-gray-500 hover:text-gray-800 dark:hover:text-gray-200"
                                            @click="toggleActive(language)"
                                        >
                                            {{ language.is_active ? $t('admin.languages.deactivate') : $t('admin.languages.activate') }}
                                        </button>
                                        <Link :href="route('admin.languages.edit', language.id)" class="text-gray-500 hover:text-gray-800 dark:hover:text-gray-200">
                                            {{ $t('common.edit') }}
                                        </Link>
                                        <button
                                            v-if="!language.is_default"
                                            type="button"
                                            class="text-red-600 hover:text-red-800"
                                            @click="destroy(language)"
                                        >
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
