<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import MoneyInput from '@/Components/Accounting/MoneyInput.vue';
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

/**
 * "+ تسجيل غياب" — الموظف، النوع، الخصم، حفظ.
 *
 * Picking a type pre-fills its default deduction, which is a suggestion
 * and nothing more: whether a given absence is charged for is decided case
 * by case, so the number stays editable and lands on the absence, not on
 * the category.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    categories: { type: Array, required: true },
    staff: { type: Array, default: () => [] },
    lockedStaffId: { type: [Number, null], default: null },
    absence: { type: Object, default: null },
    today: { type: String, required: true },
});

const emit = defineEmits(['close']);

const isEdit = computed(() => props.absence !== null);

const form = useForm({
    user_id: null,
    absence_category_id: '',
    deduction: '0.00',
    absent_on: props.today,
    reason: '',
    notes: '',
});

watch(() => props.show, (open) => {
    if (!open) return;

    form.clearErrors();

    if (props.absence) {
        form.user_id = props.absence.user_id;
        form.absence_category_id = props.absence.category_id;
        form.deduction = (props.absence.deduction_cents / 100).toFixed(2);
        form.absent_on = props.absence.absent_on;
        form.reason = props.absence.reason ?? '';
        form.notes = props.absence.notes ?? '';

        return;
    }

    form.reset();
    form.absent_on = props.today;
    form.user_id = props.lockedStaffId ?? props.staff[0]?.id ?? null;
    form.absence_category_id = props.categories[0]?.id ?? '';
    form.deduction = ((props.categories[0]?.default_deduction_cents ?? 0) / 100).toFixed(2);
});

// Only on a new absence: on an edit the stored figure is the real one and
// re-picking the type must not overwrite what was actually charged.
watch(() => form.absence_category_id, (id) => {
    if (isEdit.value) return;

    const category = props.categories.find((item) => item.id === id);

    if (category) {
        form.deduction = ((category.default_deduction_cents ?? 0) / 100).toFixed(2);
    }
});

function submit() {
    const options = { preserveScroll: true, onSuccess: () => emit('close') };

    if (isEdit.value) {
        form.patch(route('admin.accounting.absences.update', props.absence.id), options);
    } else {
        form.post(route('admin.accounting.absences.store'), options);
    }
}
</script>

<template>
    <Modal :show="show" max-width="lg" @close="emit('close')">
        <form class="space-y-4 p-6" @submit.prevent="submit">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                {{ isEdit ? $t('accounting.edit_absence') : $t('accounting.new_absence') }}
            </h2>

            <div v-if="lockedStaffId === null">
                <InputLabel for="absence-staff" :value="$t('accounting.staff_member')" />
                <select
                    id="absence-staff"
                    v-model="form.user_id"
                    required
                    class="focus-brand mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                >
                    <option v-for="member in staff" :key="member.id" :value="member.id">{{ member.name }}</option>
                </select>
                <InputError class="mt-1" :message="form.errors.user_id" />
            </div>

            <div>
                <InputLabel for="absence-type" :value="$t('accounting.absence_type')" />
                <select
                    id="absence-type"
                    v-model="form.absence_category_id"
                    required
                    class="focus-brand mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                >
                    <option v-for="category in categories" :key="category.id" :value="category.id">
                        {{ category.name }}
                    </option>
                </select>
                <InputError class="mt-1" :message="form.errors.absence_category_id" />
            </div>

            <div>
                <MoneyInput id="absence-deduction" v-model="form.deduction" :label="$t('accounting.deduction')" />
                <p class="mt-1 text-xs text-gray-500">{{ $t('accounting.deduction_hint') }}</p>
                <InputError class="mt-1" :message="form.errors.deduction_cents" />
            </div>

            <div>
                <InputLabel for="absence-date" :value="$t('accounting.date')" />
                <TextInput id="absence-date" v-model="form.absent_on" type="date" dir="ltr" class="mt-1 block w-full" />
                <InputError class="mt-1" :message="form.errors.absent_on" />
            </div>

            <div>
                <InputLabel for="absence-reason" :value="$t('accounting.reason')" />
                <TextInput id="absence-reason" v-model="form.reason" class="mt-1 block w-full" />
                <InputError class="mt-1" :message="form.errors.reason" />
            </div>

            <div>
                <InputLabel for="absence-notes" :value="$t('accounting.notes')" />
                <textarea
                    id="absence-notes"
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
