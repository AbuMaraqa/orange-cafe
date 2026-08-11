<script setup>
import NavIcon from '@/Components/NavIcon.vue';
import { useTheme } from '@/composables/useTheme';

defineProps({
    // 'inverted' for use on a colored/photo background (e.g. the customer
    // menu hero), where the default neutral gray styling wouldn't contrast.
    variant: {
        type: String,
        default: 'default',
        validator: (value) => ['default', 'inverted'].includes(value),
    },
});

const { theme, toggle } = useTheme();
</script>

<template>
    <button
        type="button"
        class="flex h-9 w-9 items-center justify-center rounded-md"
        :class="variant === 'inverted'
            ? 'text-white hover:bg-white/20'
            : 'text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800'"
        :aria-label="theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
        @click="toggle"
    >
        <NavIcon :name="theme === 'dark' ? 'sun' : 'moon'" />
    </button>
</template>
