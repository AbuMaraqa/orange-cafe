<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

defineProps({
    feedback: {
        type: Array,
        required: true,
    },
});

const { t } = useI18n();

function markRead(item) {
    router.patch(route('admin.feedback.read', item.id), {}, { preserveScroll: true });
}

function destroy(item) {
    if (confirm(t('common.are_you_sure'))) {
        router.delete(route('admin.feedback.destroy', item.id), { preserveScroll: true });
    }
}

function formatDate(value) {
    return new Date(value).toLocaleString();
}
</script>

<template>
    <Head :title="$t('nav.feedback')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('nav.feedback') }}</template>

        <p class="mb-4 text-sm text-gray-500">{{ $t('admin.feedback.hint') }}</p>

        <div v-if="feedback.length === 0" class="rounded-lg border border-gray-200 bg-white p-8 text-center text-sm text-gray-400 dark:border-gray-800 dark:bg-gray-900">
            {{ $t('admin.feedback.none') }}
        </div>

        <div v-else class="space-y-3">
            <div
                v-for="item in feedback"
                :key="item.id"
                class="rounded-lg border p-4 dark:border-gray-800"
                :class="item.is_read ? 'border-gray-200 bg-white dark:bg-gray-900' : 'border-amber-300 bg-amber-50 dark:border-amber-800 dark:bg-amber-900/20'"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500">
                            <span v-if="item.table_label" class="font-medium text-gray-700 dark:text-gray-300">{{ $t('tables.title') }} {{ item.table_label }}</span>
                            <span>{{ formatDate(item.created_at) }}</span>
                            <span v-if="!item.is_read" class="inline-flex rounded-full bg-amber-100 px-2 py-0.5 font-medium text-amber-700 dark:bg-amber-900/40 dark:text-amber-400">
                                {{ $t('admin.feedback.unread') }}
                            </span>
                        </div>
                        <p class="mt-2 whitespace-pre-wrap text-sm text-gray-900 dark:text-gray-100">{{ item.message }}</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-3 text-sm">
                        <button v-if="!item.is_read" type="button" class="text-gray-500 hover:text-gray-800 dark:hover:text-gray-200" @click="markRead(item)">
                            {{ $t('admin.feedback.mark_read') }}
                        </button>
                        <button type="button" class="text-red-600 hover:text-red-800" @click="destroy(item)">
                            {{ $t('common.delete') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
