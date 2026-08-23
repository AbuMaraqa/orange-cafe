<script setup>
/**
 * One figure, large enough to read across a counter.
 *
 * `tone` carries the meaning the cafe asked for by name — green for money
 * coming in, red for money going out, blue for a balance, amber for
 * something that needs looking at. The tone is never the only signal
 * though: the label says what the number is and `signed` amounts spell out
 * their sign, because a colour alone is no use to a colour-blind manager
 * or on a washed-out screen in daylight.
 */
defineProps({
    label: { type: String, required: true },
    value: { type: String, required: true },
    hint: { type: String, default: '' },
    tone: {
        type: String,
        default: 'neutral',
        validator: (value) => ['in', 'out', 'balance', 'warn', 'neutral'].includes(value),
    },
    icon: { type: String, default: '' },
});

const tones = {
    in: {
        wrap: 'border-green-200 bg-green-50 dark:border-green-900/50 dark:bg-green-950/30',
        value: 'text-green-700 dark:text-green-400',
    },
    out: {
        wrap: 'border-red-200 bg-red-50 dark:border-red-900/50 dark:bg-red-950/30',
        value: 'text-red-700 dark:text-red-400',
    },
    balance: {
        wrap: 'border-blue-200 bg-blue-50 dark:border-blue-900/50 dark:bg-blue-950/30',
        value: 'text-blue-700 dark:text-blue-400',
    },
    warn: {
        wrap: 'border-amber-200 bg-amber-50 dark:border-amber-900/50 dark:bg-amber-950/30',
        value: 'text-amber-700 dark:text-amber-400',
    },
    neutral: {
        wrap: 'border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900',
        value: 'text-gray-900 dark:text-gray-100',
    },
};
</script>

<template>
    <div class="rounded-xl border p-4" :class="tones[tone].wrap">
        <div class="flex items-center gap-1.5 text-xs font-medium text-gray-600 dark:text-gray-400">
            <span v-if="icon">{{ icon }}</span>
            <span>{{ label }}</span>
        </div>
        <!-- tabular-nums so a column of figures lines up digit for digit;
             without it a shortfall of 1,000 and one of 100 look the same
             length at a glance. -->
        <div class="mt-1.5 text-2xl font-bold tabular-nums" :class="tones[tone].value" dir="ltr">
            {{ value }}
        </div>
        <p v-if="hint" class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ hint }}</p>
    </div>
</template>
