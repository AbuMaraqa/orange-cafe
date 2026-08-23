<script setup>
import AbsenceModal from '@/Components/Accounting/AbsenceModal.vue';
import ExpenseModal from '@/Components/Accounting/ExpenseModal.vue';
import MoneyInput from '@/Components/Accounting/MoneyInput.vue';
import StatCard from '@/Components/Accounting/StatCard.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatDate, useMoney } from '@/composables/useMoney';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * كشف حساب الموظف.
 *
 * The quick buttons open forms with this person already chosen — "إذا كنت
 * داخل حساب أحمد وضغطت + غياب فلا أريد أن يطلب مني النظام اختيار الموظف
 * مرة أخرى".
 */
const props = defineProps({
    staffMember: { type: Object, required: true },
    summary: { type: Object, required: true },
    entries: { type: Array, required: true },
    expenseCategories: { type: Array, required: true },
    absenceCategories: { type: Array, required: true },
    recentExpenses: { type: Array, required: true },
    recentAbsences: { type: Array, required: true },
    can: { type: Object, required: true },
});

const { t } = useI18n();
const { money } = useMoney();

const showExpense = ref(false);
const showAbsence = ref(false);
const showEntry = ref(false);

const today = new Date().toISOString().slice(0, 10);

const entryForm = useForm({
    type: 'payment',
    amount: '',
    occurred_on: today,
    description: '',
    notes: '',
});

watch(showEntry, (open) => {
    if (open) {
        entryForm.reset();
        entryForm.clearErrors();
        entryForm.occurred_on = today;
    }
});

function submitEntry() {
    entryForm.post(route('admin.accounting.staff.entries.store', props.staffMember.id), {
        preserveScroll: true,
        onSuccess: () => { showEntry.value = false; },
    });
}

function deleteEntry(entry) {
    if (!confirm(t('accounting.confirm_delete'))) return;

    router.delete(route('admin.accounting.staff.entries.destroy', [props.staffMember.id, entry.id]), {
        preserveScroll: true,
    });
}

const balanceLabel = computed(() => {
    if (props.summary.balance_cents > 0) return t('accounting.balance_owed');
    if (props.summary.balance_cents < 0) return t('accounting.balance_due');

    return t('accounting.balance_clear');
});
</script>

<template>
    <Head :title="staffMember.name" />

    <AuthenticatedLayout>
        <template #header>{{ staffMember.name }}</template>

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <Link :href="route('admin.accounting.staff.index')"
                  class="text-sm text-gray-500 underline hover:text-gray-800 dark:hover:text-gray-200">
                ← {{ $t('accounting.staff') }}
            </Link>

            <!-- The employee is already decided, so these forms open one
                 field shorter than they would from anywhere else. -->
            <div class="flex flex-wrap gap-2">
                <button type="button"
                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                        @click="showExpense = true">💸 {{ $t('accounting.add_expense') }}</button>
                <button type="button"
                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                        @click="showAbsence = true">👤 {{ $t('accounting.add_absence') }}</button>
                <button v-if="can.post" type="button"
                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                        @click="showEntry = true">💳 {{ $t('accounting.post_entry') }}</button>
            </div>
        </div>

        <h3 class="mb-2 text-base font-semibold text-gray-900 dark:text-gray-100">{{ $t('accounting.financial_account') }}</h3>

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <StatCard :label="$t('accounting.total_advances')" :value="money(summary.advances_cents)" tone="neutral" />
            <StatCard :label="$t('accounting.total_expenses')" :value="money(summary.expenses_cents)" tone="out" />
            <StatCard :label="$t('accounting.total_absence_deductions')" :value="money(summary.absence_deductions_cents)" tone="warn" />
            <StatCard :label="$t('accounting.total_payments')" :value="money(summary.payments_cents)" tone="in" />
            <StatCard
                :label="$t('accounting.balance')"
                :value="money(Math.abs(summary.balance_cents))"
                :hint="balanceLabel"
                :tone="summary.balance_cents > 0 ? 'warn' : (summary.balance_cents < 0 ? 'in' : 'balance')"
            />
        </div>

        <!-- The statement. Each row shows the balance as it stood after it,
             so the last row and the card above always agree. -->
        <div class="mt-6 overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <h3 class="border-b border-gray-200 px-4 py-3 text-base font-semibold text-gray-900 dark:border-gray-800 dark:text-gray-100">
                {{ $t('accounting.statement') }}
            </h3>

            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                <thead class="bg-gray-50 dark:bg-gray-900/60">
                    <tr class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-3 text-start">{{ $t('accounting.statement_date') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('accounting.statement_description') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('accounting.statement_type') }}</th>
                        <th class="px-4 py-3 text-end">{{ $t('accounting.statement_debit') }}</th>
                        <th class="px-4 py-3 text-end">{{ $t('accounting.statement_credit') }}</th>
                        <th class="px-4 py-3 text-end">{{ $t('accounting.statement_balance') }}</th>
                        <th class="px-4 py-3 text-end"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                    <tr v-if="entries.length === 0">
                        <td colspan="7" class="px-4 py-10 text-center text-sm text-gray-400">
                            {{ $t('accounting.no_entries') }}
                        </td>
                    </tr>
                    <tr v-for="entry in entries" :key="entry.id">
                        <td class="whitespace-nowrap px-4 py-3 text-sm tabular-nums text-gray-600 dark:text-gray-400" dir="ltr">
                            {{ formatDate(entry.date) }}
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{{ entry.description }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-xs">
                            <span class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                {{ $t(`accounting.type_${entry.type}`) }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-end text-sm tabular-nums text-red-600 dark:text-red-400" dir="ltr">
                            {{ entry.debit_cents ? money(entry.debit_cents) : '—' }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-end text-sm tabular-nums text-green-600 dark:text-green-400" dir="ltr">
                            {{ entry.credit_cents ? money(entry.credit_cents) : '—' }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-end text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100" dir="ltr">
                            {{ money(entry.balance_cents) }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-end text-xs">
                            <!-- Only free-standing entries: one produced by an
                                 absence or an expense is removed by deleting
                                 that record, so the two cannot disagree. -->
                            <button v-if="can.post && !entry.source_type" type="button"
                                    class="text-red-500 hover:text-red-700" @click="deleteEntry(entry)">
                                {{ $t('common.delete') }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-6 grid gap-4 lg:grid-cols-2">
            <div class="rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <h4 class="border-b border-gray-200 px-4 py-3 text-sm font-semibold text-gray-900 dark:border-gray-800 dark:text-gray-100">
                    {{ $t('accounting.expenses') }}
                </h4>
                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    <li v-if="recentExpenses.length === 0" class="px-4 py-6 text-center text-sm text-gray-400">
                        {{ $t('accounting.no_expenses') }}
                    </li>
                    <li v-for="expense in recentExpenses" :key="expense.id" class="flex items-center gap-3 px-4 py-2.5">
                        <span class="w-24 shrink-0 text-xs tabular-nums text-gray-500" dir="ltr">{{ formatDate(expense.spent_on) }}</span>
                        <span class="min-w-0 flex-1 truncate text-sm text-gray-700 dark:text-gray-300">
                            {{ expense.category_name }} · {{ expense.reason }}
                        </span>
                        <span class="shrink-0 text-sm font-semibold tabular-nums text-red-600 dark:text-red-400" dir="ltr">
                            {{ money(expense.amount_cents) }}
                        </span>
                    </li>
                </ul>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <h4 class="border-b border-gray-200 px-4 py-3 text-sm font-semibold text-gray-900 dark:border-gray-800 dark:text-gray-100">
                    {{ $t('accounting.absences') }}
                </h4>
                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    <li v-if="recentAbsences.length === 0" class="px-4 py-6 text-center text-sm text-gray-400">
                        {{ $t('accounting.no_absences') }}
                    </li>
                    <li v-for="absence in recentAbsences" :key="absence.id" class="flex items-center gap-3 px-4 py-2.5">
                        <span class="w-24 shrink-0 text-xs tabular-nums text-gray-500" dir="ltr">{{ formatDate(absence.absent_on) }}</span>
                        <span class="min-w-0 flex-1 truncate text-sm text-gray-700 dark:text-gray-300">
                            {{ absence.category_name }}
                        </span>
                        <span class="shrink-0 text-sm font-semibold tabular-nums text-amber-600 dark:text-amber-400" dir="ltr">
                            {{ money(absence.deduction_cents) }}
                        </span>
                    </li>
                </ul>
            </div>
        </div>

        <ExpenseModal
            :show="showExpense"
            :categories="expenseCategories"
            :locked-staff-id="staffMember.id"
            :today="today"
            @close="showExpense = false"
        />
        <AbsenceModal
            :show="showAbsence"
            :categories="absenceCategories"
            :locked-staff-id="staffMember.id"
            :today="today"
            @close="showAbsence = false"
        />

        <Modal :show="showEntry" max-width="lg" @close="showEntry = false">
            <form class="space-y-4 p-6" @submit.prevent="submitEntry">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $t('accounting.post_entry') }}</h2>

                <div>
                    <InputLabel for="entry-type" :value="$t('accounting.statement_type')" />
                    <select id="entry-type" v-model="entryForm.type"
                            class="focus-brand mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300">
                        <option value="payment">{{ $t('accounting.type_payment') }}</option>
                        <option value="salary">{{ $t('accounting.type_salary') }}</option>
                        <option value="other_deduction">{{ $t('accounting.type_other_deduction') }}</option>
                        <option value="adjustment">{{ $t('accounting.type_adjustment') }}</option>
                    </select>
                    <InputError class="mt-1" :message="entryForm.errors.type" />
                </div>

                <MoneyInput id="entry-amount" v-model="entryForm.amount" :label="$t('accounting.amount')" required autofocus />
                <InputError class="mt-1" :message="entryForm.errors.amount_cents" />

                <div>
                    <InputLabel for="entry-description" :value="$t('accounting.statement_description')" />
                    <TextInput id="entry-description" v-model="entryForm.description" required class="mt-1 block w-full" />
                    <InputError class="mt-1" :message="entryForm.errors.description" />
                </div>

                <div>
                    <InputLabel for="entry-date" :value="$t('accounting.date')" />
                    <TextInput id="entry-date" v-model="entryForm.occurred_on" type="date" dir="ltr" class="mt-1 block w-full" />
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <SecondaryButton type="button" @click="showEntry = false">{{ $t('common.cancel') }}</SecondaryButton>
                    <PrimaryButton :disabled="entryForm.processing">{{ $t('common.save') }}</PrimaryButton>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
