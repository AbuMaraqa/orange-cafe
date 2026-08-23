<script setup>
/**
 * An on/off switch for a value that is saved the moment it is flipped —
 * the row in a table, not a field in a form. It reports the intent and
 * shows `pending` until the caller says otherwise, so a slow save cannot be
 * mistaken for a tap that did not register.
 */
defineProps({
    modelValue: {
        type: Boolean,
        required: true,
    },
    label: {
        type: String,
        default: '',
    },
    pending: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['update:modelValue']);
</script>

<template>
    <button
        type="button"
        role="switch"
        :aria-checked="modelValue"
        :aria-label="label"
        :title="label"
        :disabled="pending"
        class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:opacity-60"
        :class="modelValue
            ? 'bg-green-500'
            : 'bg-gray-300 dark:bg-gray-600'"
        @click="emit('update:modelValue', !modelValue)"
    >
        <!-- start-4 rather than translate-x: the admin screens run in RTL,
             where a knob translated along +x slides the wrong way. -->
        <span
            class="absolute h-4 w-4 rounded-full bg-white shadow transition-[inset-inline-start]"
            :class="modelValue ? 'start-6' : 'start-1'"
        />
    </button>
</template>
