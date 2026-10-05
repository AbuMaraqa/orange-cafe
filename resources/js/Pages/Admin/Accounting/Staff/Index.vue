<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useMoney } from '@/composables/useMoney';
import { Head, Link } from '@inertiajs/vue3';

/**
 * Everyone, with what they owe.
 *
 * Sorted by name rather than by balance: the list is used to find a
 * specific person far more often than to rank people by debt, and a list
 * that reorders itself as balances change is one nobody can build a habit
 * around.
 */
defineProps({
    staff: { type: Array, required: true },
});

const { money } = useMoney();
</script>

<template>
    <Head :title="$t('accounting.staff')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('accounting.staff') }}</template>

        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                <thead class="bg-gray-50 dark:bg-gray-900/60">
                    <tr class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-3 text-start">{{ $t('common.name') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('admin.users.role') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('accounting.balance') }}</th>
                        <th class="px-4 py-3 text-end">{{ $t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                    <tr v-for="member in staff" :key="member.id">
                        <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">
                            {{ member.name }}
                            <span v-if="member.deleted"
                                  class="ms-1 inline-flex rounded-full bg-red-100 px-2 py-0.5 text-xs font-normal text-red-700 dark:bg-red-900/40 dark:text-red-400">
                                {{ $t('admin.users.deleted_account') }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-500">
                            <span v-for="role in member.roles" :key="role"
                                  class="me-1 inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs dark:bg-gray-800">
                                {{ role }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm font-semibold tabular-nums" dir="ltr"
                            :class="member.balance_cents > 0
                                ? 'text-amber-600 dark:text-amber-400'
                                : (member.balance_cents < 0 ? 'text-green-600 dark:text-green-400' : 'text-gray-400')">
                            {{ money(Math.abs(member.balance_cents)) }}
                            <!-- Which way round is spelled out: a bare number
                                 cannot say who owes whom. -->
                            <span class="ms-1 text-xs font-normal text-gray-500">
                                {{ member.balance_cents > 0
                                    ? $t('accounting.balance_owed')
                                    : (member.balance_cents < 0 ? $t('accounting.balance_due') : $t('accounting.balance_clear')) }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-end text-sm">
                            <Link :href="route('admin.accounting.staff.show', member.id)"
                                  class="font-medium" style="color: var(--brand-primary)">
                                {{ $t('accounting.open_account') }}
                            </Link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AuthenticatedLayout>
</template>
