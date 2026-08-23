<script setup>
import TextInput from '@/Components/TextInput.vue';
import { useMoney } from '@/composables/useMoney';

/**
 * A box for typing an amount.
 *
 * The value is the text exactly as typed and it is sent that way — the
 * server multiplies by 100 (NormalisesMoney). Doing the conversion here
 * would put a JavaScript float between the user and the database, and
 * 12.10 * 100 is 1209.9999… in binary, so a piastre would go missing from
 * roughly one entry in ten with nothing to show for it.
 *
 * It is also why the box does not reformat while being typed in: the
 * product form learned that lesson the hard way, where a value re-derived
 * on every keystroke made "5.50" impossible to enter.
 */
defineProps({
    modelValue: { type: [String, Number], default: '' },
    label: { type: String, default: '' },
    id: { type: String, default: undefined },
    required: { type: Boolean, default: false },
    autofocus: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const { symbol } = useMoney();
</script>

<template>
    <div>
        <label v-if="label" :for="id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
            {{ label }}
        </label>
        <div class="relative mt-1">
            <TextInput
                :id="id"
                :model-value="modelValue"
                type="number"
                min="0"
                step="0.01"
                inputmode="decimal"
                dir="ltr"
                :required="required"
                :autofocus="autofocus"
                class="no-spinner block w-full pe-10 text-lg font-semibold"
                placeholder="0.00"
                @update:model-value="(value) => emit('update:modelValue', value)"
            />
            <span
                class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-sm text-gray-400"
            >{{ symbol() }}</span>
        </div>
    </div>
</template>
