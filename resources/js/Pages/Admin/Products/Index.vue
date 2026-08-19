<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

defineProps({
    products: {
        type: Array,
        required: true,
    },
    languages: {
        type: Array,
        required: true,
    },
});

const { t } = useI18n();
const page = usePage();

function destroy(product) {
    if (confirm(`${product.name}: ` + t('common.are_you_sure'))) {
        router.delete(route('admin.products.destroy', product.id), { preserveScroll: true });
    }
}

function languageName(code, languages) {
    return languages.find((l) => l.code === code)?.native_name ?? code;
}

function formatPrice(cents) {
    return (cents / 100).toFixed(2);
}
</script>

<template>
    <Head :title="$t('admin.products.title')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('admin.products.title') }}</template>

        <div class="mb-4 flex justify-end">
            <Link :href="route('admin.products.create')">
                <PrimaryButton>{{ $t('admin.products.add_product') }}</PrimaryButton>
            </Link>
        </div>

        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                <thead class="bg-gray-50 dark:bg-gray-900/60">
                    <tr class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-3 text-start">{{ $t('common.name') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('common.category') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('common.price') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('common.status') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('admin.products.missing_translations') }}</th>
                        <th class="px-4 py-3 text-end">{{ $t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                    <tr v-for="product in products" :key="product.id">
                        <td class="whitespace-nowrap px-4 py-3">
                            <div class="flex items-center gap-3">
                                <div class="h-10 w-10 shrink-0 overflow-hidden rounded-md bg-gray-100 dark:bg-gray-800">
                                    <img v-if="product.image_url" :src="product.image_url" class="h-full w-full object-cover" :alt="product.name" />
                                    <img
                                        v-else-if="page.props.branding.logo_url"
                                        :src="page.props.branding.logo_url"
                                        class="h-full w-full object-contain p-1 opacity-70"
                                        :alt="product.name"
                                    />
                                </div>
                                <div>
                                    <div class="font-medium text-gray-900 dark:text-gray-100">{{ product.name }}</div>
                                    <div v-if="product.tags.length" class="mt-0.5 flex gap-1">
                                        <span v-for="tag in product.tags" :key="tag" class="text-xs text-gray-400">#{{ tag }}</span>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                            <div class="flex flex-wrap gap-1">
                                <span v-if="product.menus.length === 0" class="text-gray-400">—</span>
                                <span
                                    v-for="menuName in product.menus"
                                    :key="menuName"
                                    class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300"
                                >{{ menuName }}</span>
                            </div>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm tabular-nums text-gray-600 dark:text-gray-400">
                            {{ formatPrice(product.price_cents) }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <span
                                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="product.is_active
                                    ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400'
                                    : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'"
                            >
                                {{ product.is_active ? $t('common.active') : $t('common.inactive') }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <span v-if="product.missing_locales.length === 0" class="text-sm text-gray-400">—</span>
                            <span
                                v-for="code in product.missing_locales"
                                :key="code"
                                class="me-1 inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/40 dark:text-amber-400"
                            >
                                {{ languageName(code, languages) }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-end text-sm">
                            <div class="flex items-center justify-end gap-3">
                                <Link :href="route('admin.products.edit', product.id)" class="text-gray-500 hover:text-gray-800 dark:hover:text-gray-200">
                                    {{ $t('common.edit') }}
                                </Link>
                                <button type="button" class="text-red-600 hover:text-red-800" @click="destroy(product)">
                                    {{ $t('common.delete') }}
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AuthenticatedLayout>
</template>
