<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import LanguageSwitcher from '@/Components/LanguageSwitcher.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div
        class="flex min-h-screen flex-col items-center bg-gray-100 pt-6 sm:justify-center sm:pt-0 dark:bg-gray-900"
    >
        <div class="mb-4 flex w-full max-w-md items-center justify-between px-4 sm:px-0">
            <Link href="/">
                <ApplicationLogo class="h-16 w-16 fill-current text-gray-500" />
            </Link>
            <div class="flex items-center gap-2">
                <ThemeToggle />
                <LanguageSwitcher />
            </div>
        </div>

        <div
            class="mt-2 w-full overflow-hidden bg-white px-6 py-4 shadow-md sm:max-w-md sm:rounded-lg dark:bg-gray-800"
        >
            <slot />
        </div>
    </div>
</template>
