<script setup>
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatDate, formatDateTime, useMoney } from '@/composables/useMoney';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

/**
 * The nights that have been signed off.
 *
 * The figures are the stored ones, exactly as they were at the moment of
 * signing — not recomputed. A refund processed a week later must not
 * rewrite what a manager put their name to.
 */
const props = defineProps({
    closings: { type: Array, required: true },
    filters: { type: Object, required: true },
    can: { type: Object, required: true },
});

const { t } = useI18n();
const { money, signed } = useMoney();

function reload(overrides = {}) {
    router.get(route('admin.accounting.cashbox.closings'), {
        from: overrides.from ?? props.filters.from,
        to: overrides.to ?? props.filters.to,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

function reopen(closing) {
    if (!confirm(t('accounting.confirm_reopen'))) return;

    router.post(route('admin.accounting.cashbox.reopen'), { date: closing.business_date }, { preserveScroll: true });
}

const stateClasses = {
    over: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400',
    short: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400',
    balanced: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400',
};
</script>

<template>
    <Head :title="$t('accounting.closings')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('accounting.closings') }}</template>

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <Link :href="route('admin.accounting.cashbox')"
                  class="text-sm text-gray-500 underline hover:text-gray-800 dark:hover:text-gray-200">
                ← {{ $t('accounting.cashbox') }}
            </Link>

            <div class="flex gap-2">
                <TextInput :model-value="filters.from" type="date" dir="ltr" class="text-sm"
                           @update:model-value="(value) => reload({ from: value })" />
                <TextInput :model-value="filters.to" type="date" dir="ltr" class="text-sm"
                           @update:model-value="(value) => reload({ to: value })" />
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                <thead class="bg-gray-50 dark:bg-gray-900/60">
                    <tr class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-3 text-start">{{ $t('accounting.date') }}</th>
                        <th class="px-4 py-3 text-end">{{ $t('accounting.opening_balance') }}</th>
                        <th class="px-4 py-3 text-end">{{ $t('accounting.collected_today') }}</th>
                        <th class="px-4 py-3 text-end">{{ $t('accounting.expenses_today') }}</th>
                        <th class="px-4 py-3 text-end">{{ $t('accounting.expected_amount') }}</th>
                        <th class="px-4 py-3 text-end">{{ $t('accounting.counted_amount') }}</th>
                        <th class="px-4 py-3 text-end">{{ $t('accounting.difference') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('accounting.closed_by') }}</th>
                        <th class="px-4 py-3 text-end"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                    <tr v-if="closings.length === 0">
                        <td colspan="9" class="px-4 py-10 text-center text-sm text-gray-400">—</td>
                    </tr>
                    <tr v-for="closing in closings" :key="closing.id">
                        <td class="whitespace-nowrap px-4 py-3 text-sm tabular-nums font-medium text-gray-900 dark:text-gray-100" dir="ltr">
                            {{ formatDate(closing.business_date) }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-end text-sm tabular-nums text-gray-600 dark:text-gray-400" dir="ltr">{{ money(closing.opening_cents) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-end text-sm tabular-nums text-green-600 dark:text-green-400" dir="ltr">{{ money(closing.collected_cents) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-end text-sm tabular-nums text-red-600 dark:text-red-400" dir="ltr">{{ money(closing.expenses_cents) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-end text-sm tabular-nums text-gray-900 dark:text-gray-100" dir="ltr">{{ money(closing.expected_cents) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-end text-sm tabular-nums text-gray-900 dark:text-gray-100" dir="ltr">{{ money(closing.counted_cents) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-end text-sm">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums"
                                  :class="stateClasses[closing.difference_state]" dir="ltr">
                                {{ signed(closing.difference_cents) }}
                            </span>
                            <span class="ms-1 text-xs text-gray-500">{{ $t(`accounting.difference_${closing.difference_state}`) }}</span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-xs text-gray-500">
                            {{ closing.closed_by_name ?? '—' }}
                            <span class="block">{{ formatDateTime(closing.closed_at) }}</span>
                            <!-- A reopened night stays visible as reopened:
                                 a close that can be silently undone is not a
                                 close. -->
                            <span v-if="closing.reopened_by_name" class="block text-amber-600 dark:text-amber-400">
                                {{ $t('accounting.reopened_by') }}: {{ closing.reopened_by_name }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-end text-sm">
                            <button v-if="can.reopen && closing.is_closed" type="button"
                                    class="text-xs text-amber-600 hover:text-amber-800"
                                    @click="reopen(closing)">{{ $t('accounting.reopen_day') }}</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AuthenticatedLayout>
</template>
