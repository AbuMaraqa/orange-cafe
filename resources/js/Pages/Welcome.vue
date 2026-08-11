<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import LanguageSwitcher from '@/Components/LanguageSwitcher.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    canLogin: Boolean,
    canRegister: Boolean,
});
</script>

<template>
    <Head title="Orange" />

    <div class="flex min-h-screen flex-col bg-gray-50 dark:bg-gray-900">
        <header class="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-6">
            <ApplicationLogo class="h-9 w-auto fill-current text-gray-800 dark:text-gray-200" />
            <div class="flex items-center gap-2">
                <ThemeToggle />
                <LanguageSwitcher />
            </div>
        </header>

        <main class="mx-auto flex w-full max-w-5xl flex-1 flex-col items-center justify-center px-6 text-center">
            <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                Orange
            </h1>
            <p class="mt-2 max-w-md text-sm text-gray-600 dark:text-gray-400">
                {{ $t('welcome.tagline') }}
            </p>

            <div class="mt-8 flex items-center gap-3">
                <Link
                    :href="route('menu.index')"
                    class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800"
                >
                    {{ $t('welcome.view_menu') }}
                </Link>
                <Link
                    v-if="canLogin"
                    :href="route('login')"
                    class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white"
                >
                    {{ $t('welcome.login') }}
                </Link>
            </div>
        </main>
    </div>
</template>
