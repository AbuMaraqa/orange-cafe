<script setup>
import ExpenseModal from '@/Components/Accounting/ExpenseModal.vue';
import StatCard from '@/Components/Accounting/StatCard.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatDate, useMoney } from '@/composables/useMoney';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    expenses: { type: Array, required: true },
    filters: { type: Object, required: true },
    categories: { type: Array, required: true },
    staff: { type: Array, required: true },
    stats: { type: Object, required: true },
    byCategory: { type: Array, required: true },
    today: { type: String, required: true },
});

const { t } = useI18n();
const { money } = useMoney();

const showModal = ref(false);
const editing = ref(null);
const search = ref(props.filters.search ?? '');
let searchTimer = null;

function reload(overrides = {}) {
    const pick = (key, fallback) => (key in overrides ? overrides[key] : fallback);

    const params = {
        from: pick('from', props.filters.from),
        to: pick('to', props.filters.to),
        category_id: pick('category_id', props.filters.category_id),
        user_id: pick('user_id', props.filters.user_id),
        search: pick('search', search.value),
    };

    for (const key of Object.keys(params)) {
        if (params[key] === null || params[key] === '' || params[key] === undefined) delete params[key];
    }

    router.get(route('admin.accounting.expenses.index'), params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function onSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(reload, 300);
}

function openNew() {
    editing.value = null;
    showModal.value = true;
}

function openEdit(expense) {
    editing.value = expense;
    showModal.value = true;
}

function destroy(expense) {
    if (!confirm(t('accounting.confirm_delete'))) return;

    router.delete(route('admin.accounting.expenses.destroy', expense.id), { preserveScroll: true });
}

const selectClass = 'focus-brand block w-full rounded-md border-gray-300 py-1.5 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300';
</script>

<template>
    <Head :title="$t('accounting.expenses')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('accounting.expenses') }}</template>

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <Link
                :href="route('admin.accounting.expense-categories.index')"
                class="text-sm text-gray-500 underline hover:text-gray-800 dark:hover:text-gray-200"
            >{{ $t('accounting.expense_categories') }}</Link>

            <PrimaryButton type="button" @click="openNew">+ {{ $t('accounting.new_expense') }}</PrimaryButton>
        </div>

        <!-- The fixed windows never move with the filter: a filtered view
             still has to report the true day and month. -->
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <StatCard :label="$t('accounting.today_total')" :value="money(stats.today_cents)" tone="out" icon="📤" />
            <StatCard :label="$t('accounting.week_total')" :value="money(stats.week_cents)" tone="neutral" />
            <StatCard :label="$t('accounting.month_total')" :value="money(stats.month_cents)" tone="neutral" />
            <StatCard :label="$t('accounting.range_total')" :value="money(stats.range_cents)" tone="balance"
                      :hint="`${stats.range_count} ${$t('accounting.count')}`" />
        </div>

        <div class="mt-4 rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
                <TextInput :model-value="filters.from" type="date" dir="ltr" class="text-sm"
                           @update:model-value="(value) => reload({ from: value })" />
                <TextInput :model-value="filters.to" type="date" dir="ltr" class="text-sm"
                           @update:model-value="(value) => reload({ to: value })" />

                <select :class="selectClass" :value="filters.category_id ?? ''"
                        @change="reload({ category_id: $event.target.value || null })">
                    <option value="">{{ $t('accounting.all_categories') }}</option>
                    <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                </select>

                <select :class="selectClass" :value="filters.user_id ?? ''"
                        @change="reload({ user_id: $event.target.value || null })">
                    <option value="">{{ $t('accounting.all_staff') }}</option>
                    <option v-for="member in staff" :key="member.id" :value="member.id">{{ member.name }}</option>
                </select>

                <TextInput v-model="search" type="search" class="text-sm"
                           :placeholder="$t('accounting.reason')" @input="onSearch" />
            </div>
        </div>

        <div v-if="byCategory.length" class="mt-4 flex flex-wrap gap-2">
            <span v-for="row in byCategory" :key="row.id"
                  class="inline-flex items-center gap-2 rounded-full bg-gray-100 px-3 py-1 text-xs dark:bg-gray-800">
                <span class="font-medium text-gray-700 dark:text-gray-300">{{ row.name }}</span>
                <span class="tabular-nums text-gray-500" dir="ltr">{{ money(row.total_cents) }}</span>
            </span>
        </div>

        <div class="mt-4 overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                <thead class="bg-gray-50 dark:bg-gray-900/60">
                    <tr class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-3 text-start">{{ $t('accounting.date') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('accounting.category') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('accounting.reason') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('accounting.staff_member') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('accounting.amount') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('accounting.recorded_by') }}</th>
                        <th class="px-4 py-3 text-end">{{ $t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                    <tr v-if="expenses.length === 0">
                        <td colspan="7" class="px-4 py-10 text-center text-sm text-gray-400">
                            {{ $t('accounting.no_expenses') }}
                        </td>
                    </tr>
                    <tr v-for="expense in expenses" :key="expense.id">
                        <td class="whitespace-nowrap px-4 py-3 text-sm tabular-nums text-gray-600 dark:text-gray-400" dir="ltr">
                            {{ formatDate(expense.spent_on) }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                            {{ expense.category_name }}
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{{ expense.reason || '—' }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm">
                            <Link v-if="expense.user_id" :href="route('admin.accounting.staff.show', expense.user_id)"
                                  class="font-medium" style="color: var(--brand-primary)">{{ expense.user_name }}</Link>
                            <span v-else class="text-gray-400">—</span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm font-semibold tabular-nums text-red-600 dark:text-red-400" dir="ltr">
                            {{ money(expense.amount_cents) }}
                            <span v-if="!expense.paid_from_cash" class="ms-1 text-xs font-normal text-gray-400">🏛️</span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-xs text-gray-500">{{ expense.recorded_by_name ?? '—' }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-end text-sm">
                            <div class="flex items-center justify-end gap-3">
                                <button v-if="!expense.day_is_closed" type="button"
                                        class="text-gray-500 hover:text-gray-800 dark:hover:text-gray-200"
                                        @click="openEdit(expense)">{{ $t('common.edit') }}</button>
                                <button v-if="!expense.day_is_closed" type="button"
                                        class="text-red-600 hover:text-red-800"
                                        @click="destroy(expense)">{{ $t('common.delete') }}</button>
                                <span v-if="expense.day_is_closed" class="text-xs text-gray-400">
                                    🔒 {{ $t('accounting.day_closed_badge') }}
                                </span>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ExpenseModal
            :show="showModal"
            :categories="categories"
            :staff="staff"
            :expense="editing"
            :today="today"
            @close="showModal = false"
        />
    </AuthenticatedLayout>
</template>
