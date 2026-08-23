<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import MoneyInput from '@/Components/Accounting/MoneyInput.vue';
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

/**
 * Cash in or out for a reason that is not a customer bill.
 *
 * Order takings never come through here — they are already on the order.
 * The types offered are only the ones a person actually enters by hand.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    today: { type: String, required: true },
    /** 'in' opens on a collection, 'out' on a withdrawal. */
    initialDirection: { type: String, default: 'in' },
});

const emit = defineEmits(['close']);

const form = useForm({
    direction: 'in',
    type: 'collection',
    amount: '',
    occurred_on: props.today,
    description: '',
    notes: '',
});

const typesByDirection = {
    in: ['collection', 'float'],
    out: ['withdrawal', 'refund'],
};

watch(() => props.show, (open) => {
    if (!open) return;

    form.reset();
    form.clearErrors();
    form.occurred_on = props.today;
    form.direction = props.initialDirection;
    form.type = typesByDirection[props.initialDirection][0];
});

// Keeping the type valid for the direction: a "سحب من الصندوق" that is
// also an "in" is a row that contradicts itself, and the server would
// reject it after the user had already filled the rest of the form.
watch(() => form.direction, (direction) => {
    if (!typesByDirection[direction].includes(form.type)) {
        form.type = typesByDirection[direction][0];
    }
});

function submit() {
    form.post(route('admin.accounting.cashbox.movements.store'), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <Modal :show="show" max-width="lg" @close="emit('close')">
        <form class="space-y-4 p-6" @submit.prevent="submit">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                {{ $t('accounting.add_movement') }}
            </h2>

            <div class="grid grid-cols-2 gap-2">
                <button
                    v-for="direction in ['in', 'out']"
                    :key="direction"
                    type="button"
                    class="rounded-lg border-2 px-4 py-3 text-sm font-semibold transition"
                    :class="form.direction === direction
                        ? (direction === 'in'
                            ? 'border-green-500 bg-green-50 text-green-700 dark:bg-green-950/40 dark:text-green-400'
                            : 'border-red-500 bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-400')
                        : 'border-gray-200 text-gray-500 dark:border-gray-700'"
                    @click="form.direction = direction"
                >
                    {{ direction === 'in' ? '➕ ' + $t('accounting.direction_in') : '➖ ' + $t('accounting.direction_out') }}
                </button>
            </div>

            <div>
                <InputLabel for="movement-type" :value="$t('accounting.category')" />
                <select
                    id="movement-type"
                    v-model="form.type"
                    class="focus-brand mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                >
                    <option v-for="type in typesByDirection[form.direction]" :key="type" :value="type">
                        {{ $t(`accounting.movement_${type}`) }}
                    </option>
                </select>
                <InputError class="mt-1" :message="form.errors.type" />
            </div>

            <MoneyInput id="movement-amount" v-model="form.amount" :label="$t('accounting.amount')" required autofocus />
            <InputError class="mt-1" :message="form.errors.amount_cents" />

            <div>
                <InputLabel for="movement-description" :value="$t('accounting.description')" />
                <TextInput id="movement-description" v-model="form.description" required class="mt-1 block w-full" />
                <InputError class="mt-1" :message="form.errors.description" />
            </div>

            <div>
                <InputLabel for="movement-date" :value="$t('accounting.date')" />
                <TextInput id="movement-date" v-model="form.occurred_on" type="date" dir="ltr" class="mt-1 block w-full" />
                <InputError class="mt-1" :message="form.errors.occurred_on" />
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
