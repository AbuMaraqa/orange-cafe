<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import MoneyInput from '@/Components/Accounting/MoneyInput.vue';
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

/**
 * "+ إضافة مصروف" — الفئة، المبلغ، السبب، حفظ.
 *
 * Everything the entry implies happens on the server: the drawer, the
 * employee's account, the day's totals. Nothing here asks the user to do
 * any of it, and nothing here computes a balance.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    categories: { type: Array, required: true },
    staff: { type: Array, default: () => [] },
    /** Pre-selected and locked when opened from an employee's page. */
    lockedStaffId: { type: [Number, null], default: null },
    expense: { type: Object, default: null },
    today: { type: String, required: true },
});

const emit = defineEmits(['close']);

const isEdit = computed(() => props.expense !== null);

const form = useForm({
    expense_category_id: '',
    user_id: null,
    amount: '',
    spent_on: props.today,
    reason: '',
    notes: '',
    paid_from_cash: true,
});

// Shown only when the expense is not already tied to an employee by where
// it was opened from — one less decision on the common path.
const linkToStaff = ref(false);

watch(() => props.show, (open) => {
    if (!open) return;

    form.clearErrors();

    if (props.expense) {
        form.expense_category_id = props.expense.category_id;
        form.user_id = props.expense.user_id;
        form.amount = (props.expense.amount_cents / 100).toFixed(2);
        form.spent_on = props.expense.spent_on;
        form.reason = props.expense.reason;
        form.notes = props.expense.notes ?? '';
        form.paid_from_cash = props.expense.paid_from_cash;
        linkToStaff.value = props.expense.user_id !== null;

        return;
    }

    form.reset();
    // Defaults that save a keystroke on nearly every entry.
    form.spent_on = props.today;
    form.user_id = props.lockedStaffId;
    form.expense_category_id = props.categories[0]?.id ?? '';
    linkToStaff.value = props.lockedStaffId !== null;
});

watch(linkToStaff, (linked) => {
    if (!linked) form.user_id = null;
});

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    };

    if (isEdit.value) {
        form.patch(route('admin.accounting.expenses.update', props.expense.id), options);
    } else {
        form.post(route('admin.accounting.expenses.store'), options);
    }
}
</script>

<template>
    <Modal :show="show" max-width="lg" @close="emit('close')">
        <form class="space-y-4 p-6" @submit.prevent="submit">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                {{ isEdit ? $t('accounting.edit_expense') : $t('accounting.new_expense') }}
            </h2>

            <div>
                <InputLabel for="expense-category" :value="$t('accounting.category')" />
                <select
                    id="expense-category"
                    v-model="form.expense_category_id"
                    required
                    class="focus-brand mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                >
                    <option v-for="category in categories" :key="category.id" :value="category.id">
                        {{ category.name }}
                    </option>
                </select>
                <InputError class="mt-1" :message="form.errors.expense_category_id" />
            </div>

            <MoneyInput
                id="expense-amount"
                v-model="form.amount"
                :label="$t('accounting.amount')"
                required
                autofocus
            />
            <InputError class="mt-1" :message="form.errors.amount_cents" />

            <div>
                <InputLabel for="expense-reason" :value="$t('accounting.reason')" />
                <TextInput id="expense-reason" v-model="form.reason" required class="mt-1 block w-full" />
                <InputError class="mt-1" :message="form.errors.reason" />
            </div>

            <div>
                <InputLabel for="expense-date" :value="$t('accounting.date')" />
                <TextInput id="expense-date" v-model="form.spent_on" type="date" dir="ltr" class="mt-1 block w-full" />
                <InputError class="mt-1" :message="form.errors.spent_on" />
            </div>

            <!-- Hidden entirely when the employee is already decided by
                 where this was opened from. -->
            <template v-if="lockedStaffId === null">
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <Checkbox v-model:checked="linkToStaff" />
                    {{ $t('accounting.linked_to_staff') }}
                </label>

                <div v-if="linkToStaff">
                    <InputLabel for="expense-staff" :value="$t('accounting.staff_member')" />
                    <select
                        id="expense-staff"
                        v-model="form.user_id"
                        class="focus-brand mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                    >
                        <option :value="null">{{ $t('accounting.no_staff_member') }}</option>
                        <option v-for="member in staff" :key="member.id" :value="member.id">{{ member.name }}</option>
                    </select>
                    <InputError class="mt-1" :message="form.errors.user_id" />
                </div>
            </template>

            <label class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                <Checkbox v-model:checked="form.paid_from_cash" class="mt-0.5" />
                <span>
                    {{ $t('accounting.paid_from_cash') }}
                    <span class="block text-xs text-gray-500">{{ $t('accounting.paid_from_cash_hint') }}</span>
                </span>
            </label>

            <div>
                <InputLabel for="expense-notes" :value="$t('accounting.notes')" />
                <textarea
                    id="expense-notes"
                    v-model="form.notes"
                    rows="2"
                    class="focus-brand mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                />
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <SecondaryButton type="button" @click="emit('close')">{{ $t('common.cancel') }}</SecondaryButton>
                <PrimaryButton :disabled="form.processing">
                    {{ form.processing ? $t('accounting.loading') : $t('common.save') }}
                </PrimaryButton>
            </div>
        </form>
    </Modal>
</template>
