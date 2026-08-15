<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import NavIcon from '@/Components/NavIcon.vue';

defineProps({
    service: {
        type: Object,
        required: true,
    },
    tableLabel: {
        type: String,
        required: true,
    },
    menuUrl: {
        type: String,
        required: true,
    },
});

const page = usePage();
const { t } = useI18n();
const branding = page.props.branding;
</script>

<template>
    <Head :title="t('menu.service_requested')" />

    <div class="mx-auto flex min-h-screen max-w-md flex-col items-center justify-center px-6 py-10 text-center dark:bg-gray-950">
        <div v-if="branding.logo_url" class="mb-6 h-16 w-16 overflow-hidden rounded-full bg-white shadow-sm ring-1 ring-gray-100 dark:ring-gray-800">
            <img :src="branding.logo_url" class="h-full w-full object-contain p-2" :alt="branding.name" />
        </div>

        <div
            class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full text-white"
            style="background-color: var(--brand-primary)"
        ><NavIcon name="check" class="h-7 w-7" /></div>

        <div
            class="mx-auto flex h-24 w-24 items-center justify-center overflow-hidden rounded-2xl text-5xl text-white shadow-lg"
            :style="{ backgroundColor: service.color }"
        >
            <img v-if="service.image_url" :src="service.image_url" class="h-full w-full object-cover" :alt="service.name" />
            <template v-else>{{ service.icon }}</template>
        </div>
        <h1 class="mt-3 text-xl font-semibold text-gray-900 dark:text-gray-100">{{ service.name }}</h1>
        <p class="mt-1 text-sm text-gray-500">{{ t('tables.title') }} {{ tableLabel }}</p>

        <p class="mt-4 text-sm font-medium text-gray-700 dark:text-gray-300">{{ t('menu.service_requested') }}</p>
        <p class="mt-1 text-sm text-gray-500">{{ t('menu.waiter_on_the_way') }}</p>

        <Link :href="menuUrl" class="mt-8 text-sm text-gray-500 underline">
            {{ t('menu.back_to_menu') }}
        </Link>
    </div>
</template>
