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
            <!-- Said the way the person would say it — "I put money in" —
                 rather than as a ledger direction. Whoever opens this has
                 just done something with their hands at the drawer. -->
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                {{ form.direction === 'in' ? $t('accounting.drawer_put_in') : $t('accounting.drawer_took_out') }}
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
                    {{ direction === 'in' ? '➕ ' + $t('accounting.drawer_kind_in') : '➖ ' + $t('accounting.drawer_kind_out') }}
                </button>
            </div>

            <!-- Two choices per direction, so they are laid out as cards
                 with an example each rather than hidden in a dropdown whose
                 options only make sense to somebody who already knows them. -->
            <fieldset>
                <legend class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $t('accounting.drawer_what_kind') }}</legend>
                <div class="mt-1 grid gap-2 sm:grid-cols-2">
                    <label
                        v-for="type in typesByDirection[form.direction]"
                        :key="type"
                        class="flex cursor-pointer items-start gap-2 rounded-lg border-2 p-3 transition"
                        :class="form.type === type
                            ? 'border-gray-900 bg-gray-50 dark:border-gray-200 dark:bg-gray-800'
                            : 'border-gray-200 hover:border-gray-300 dark:border-gray-700'"
                    >
                        <input v-model="form.type" type="radio" name="movement-type" :value="type" class="mt-0.5" />
                        <span>
                            <span class="block text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $t(`accounting.drawer_type_${type}`) }}</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $t(`accounting.drawer_type_${type}_hint`) }}</span>
                        </span>
                    </label>
                </div>
                <InputError class="mt-1" :message="form.errors.type" />
            </fieldset>

            <MoneyInput id="movement-amount" v-model="form.amount" :label="$t('accounting.amount')" required autofocus />
            <InputError class="mt-1" :message="form.errors.amount_cents" />

            <div>
                <InputLabel for="movement-description" :value="$t('accounting.drawer_reason')" />
                <TextInput id="movement-description" v-model="form.description" required class="mt-1 block w-full"
                           :placeholder="$t('accounting.drawer_reason_placeholder')" />
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
