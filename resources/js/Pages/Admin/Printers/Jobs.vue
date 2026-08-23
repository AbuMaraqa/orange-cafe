<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { formatDateTime } from '@/composables/useMoney';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

/**
 * "الطلبات غير المطبوعة".
 *
 * The list exists because an order is saved whether or not any printer
 * works, which means "saved" and "printed" are different facts and only one
 * of them used to be recorded. Reprinting from here is the only way a held
 * ticket reaches paper — a printer coming back never empties this list by
 * itself, because only a person knows whether table 6 is still sitting
 * there.
 */
const props = defineProps({
    jobs: { type: Array, required: true },
    filters: { type: Object, required: true },
    counts: { type: Object, required: true },
    pagination: { type: Object, required: true },
});

const { t } = useI18n();

function reload(status) {
    // Back to page one, keeping the page size: a new filter is a new list,
    // and page 6 of the old one means nothing in it. The size is a
    // preference rather than a filter, so it survives.
    router.get(route('admin.print-jobs.index'), { status, per_page: props.pagination.per_page }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function reprint(job) {
    if (!confirm(t('printers.confirm_reprint'))) return;

    router.post(route('admin.print-jobs.reprint', job.id), {}, { preserveScroll: true });
}

function cancel(job) {
    if (!confirm(t('printers.confirm_cancel'))) return;

    router.post(route('admin.print-jobs.cancel', job.id), {}, { preserveScroll: true });
}

// Clears the backlog the admin is currently looking at — the same filter,
// not every unprinted job everywhere. See PrintJobController::cancelAll.
function cancelAll() {
    if (!confirm(t('printers.confirm_cancel_all'))) return;

    router.post(
        route('admin.print-jobs.cancel-all'),
        { printer_id: props.filters.printer_id ?? null },
        { preserveScroll: true },
    );
}

const statusClasses = {
    printed: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400',
    pending: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
    printing: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400',
    failed: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400',
    printer_offline: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400',
    cancelled: 'bg-gray-100 text-gray-500 line-through dark:bg-gray-800 dark:text-gray-400',
};

const tabs = ['unprinted', 'printed', 'all'];
</script>

<template>
    <Head :title="$t('printers.jobs_title')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('printers.jobs_title') }}</template>

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <Link :href="route('admin.printers.index')"
                  class="text-sm text-gray-500 underline hover:text-gray-800 dark:hover:text-gray-200">
                ← {{ $t('nav.printers') }}
            </Link>

            <div class="flex gap-1 rounded-lg border border-gray-200 p-1 dark:border-gray-700">
                <button v-for="tab in tabs" :key="tab" type="button"
                        class="rounded px-3 py-1.5 text-sm font-medium transition"
                        :class="filters.status === tab
                            ? 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900'
                            : 'text-gray-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800'"
                        @click="reload(tab)">
                    {{ $t(`printers.tab_${tab}`) }}
                    <span v-if="tab === 'unprinted' && counts.unprinted" class="ms-1 text-xs">({{ counts.unprinted }})</span>
                </button>
            </div>

            <!-- Only where it means something: on the "printed" tab there
                 is nothing to cancel, and offering it there invites the
                 question of what it would even do. -->
            <button
                v-if="filters.status !== 'printed' && counts.unprinted > 0"
                type="button"
                class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-600 transition hover:border-red-300 hover:text-red-600 dark:border-gray-700 dark:text-gray-400 dark:hover:border-red-800 dark:hover:text-red-400"
                @click="cancelAll"
            >
                {{ $t('printers.cancel_all') }}
            </button>
        </div>

        <!-- Said plainly, because the behaviour surprises people who expect
             a recovered printer to catch up on its own. -->
        <div v-if="counts.needs_attention > 0"
             class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-300">
            {{ $t('printers.manual_only_notice') }}
        </div>

        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                <thead class="bg-gray-50 dark:bg-gray-900/60">
                    <tr class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-3 text-start">{{ $t('printers.order') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('printers.printer') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('printers.station') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('printers.items') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('common.status') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('printers.attempts') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('printers.last_attempt') }}</th>
                        <th class="px-4 py-3 text-end">{{ $t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                    <tr v-if="jobs.length === 0">
                        <td colspan="8" class="px-4 py-10 text-center text-sm text-gray-400">
                            {{ $t('printers.no_jobs') }}
                        </td>
                    </tr>
                    <tr v-for="job in jobs" :key="job.id">
                        <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">
                            {{ job.order_reference ?? '—' }}
                            <span class="block font-mono text-xs font-normal text-gray-400">{{ job.reference }}</span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
                            {{ job.printer_name }}
                            <span v-if="job.printed_on" class="block text-xs text-amber-600 dark:text-amber-400">
                                → {{ job.printed_on }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{{ job.station }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm tabular-nums text-gray-600 dark:text-gray-400">{{ job.items_count }}</td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClasses[job.status]">
                                {{ $t(`printers.job_status.${job.status}`) }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm tabular-nums text-gray-600 dark:text-gray-400">
                            {{ job.attempts }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-xs text-gray-500">
                            {{ job.last_attempt_at ? formatDateTime(job.last_attempt_at) : '—' }}
                            <span v-if="job.last_error" class="block max-w-64 truncate text-red-500" :title="job.last_error">
                                {{ job.last_error }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-end text-sm">
                            <button v-if="job.can_reprint" type="button"
                                    class="font-medium" style="color: var(--brand-primary)"
                                    @click="reprint(job)">
                                {{ $t('printers.reprint') }}
                            </button>
                            <button v-if="job.can_cancel" type="button"
                                    class="ms-3 font-medium text-gray-400 transition hover:text-red-600 dark:hover:text-red-400"
                                    @click="cancel(job)">
                                {{ $t('printers.cancel') }}
                            </button>
                            <span v-else-if="job.status === 'printed'" class="text-xs text-gray-400">
                                {{ formatDateTime(job.printed_at) }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>

            <Pagination :meta="pagination" />
        </div>
    </AuthenticatedLayout>
</template>
