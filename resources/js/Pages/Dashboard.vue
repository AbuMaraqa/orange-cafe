<script setup>
import { computed } from 'vue';
import NavIcon from '@/Components/NavIcon.vue';
import OrderCard from '@/Components/Staff/OrderCard.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useLiveOrders } from '@/composables/useLiveOrders';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    orders: {
        type: Array,
        required: true,
    },
    showOrders: {
        type: Boolean,
        default: false,
    },
});

const { t } = useI18n();
const page = usePage();

const roles = computed(() => page.props.auth.roles ?? []);
const isAdmin = computed(() => roles.value.includes('admin'));
const hasRole = (role) => roles.value.includes(role) || isAdmin.value;

const { orders } = props.showOrders ? useLiveOrders(() => props.orders) : { orders: [] };
const sortedOrders = computed(() => [...orders].sort((a, b) => new Date(b.created_at) - new Date(a.created_at)));

const shortcuts = computed(() => [
    { label: t('nav.orders'), icon: 'clipboardList', route: 'admin.orders.index', visible: isAdmin.value },
    { label: t('waiter.new_order'), icon: 'clipboardList', route: 'waiter.orders.create', visible: hasRole('waiter') },
    { label: t('nav.waiter'), icon: 'users', route: 'waiter.index', visible: hasRole('waiter') },
    { label: t('nav.cashier'), icon: 'cash', route: 'cashier.index', visible: hasRole('cashier') },
    { label: t('nav.kitchen'), icon: 'fire', route: 'kitchen.index', visible: hasRole('kitchen') },
    { label: t('nav.tables'), icon: 'table', route: 'admin.tables.index', visible: isAdmin.value },
    { label: t('nav.menus'), icon: 'book', route: 'admin.menus.index', visible: isAdmin.value },
    { label: t('nav.categories'), icon: 'grid', route: 'admin.categories.index', visible: isAdmin.value },
    { label: t('nav.products'), icon: 'tag', route: 'admin.products.index', visible: isAdmin.value },
    { label: t('nav.printers'), icon: 'printer', route: 'admin.printers.index', visible: isAdmin.value },
    { label: t('nav.settings'), icon: 'settings', route: 'admin.settings.edit', visible: isAdmin.value },
    { label: t('nav.languages'), icon: 'languages', route: 'admin.languages.index', visible: isAdmin.value },
].filter((item) => item.visible));
</script>

<template>
    <Head :title="$t('nav.dashboard')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('nav.dashboard') }}</template>

        <div v-if="showOrders">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ $t('dashboard.active_orders') }}
                </h3>
                <span
                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="sortedOrders.length > 0
                        ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400'
                        : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'"
                >
                    {{ t('staff.orders_count', { count: sortedOrders.length }) }}
                </span>
            </div>

            <p v-if="sortedOrders.length === 0" class="rounded-lg border border-gray-200 bg-white p-6 text-sm text-gray-500 dark:border-gray-800 dark:bg-gray-900">
                {{ $t('staff.no_orders') }}
            </p>

            <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <OrderCard v-for="order in sortedOrders" :key="order.id" :order="order" />
            </div>
        </div>

        <div v-if="shortcuts.length > 0" :class="showOrders ? 'mt-8' : ''">
            <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ $t('dashboard.quick_access') }}
            </h3>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                <Link
                    v-for="shortcut in shortcuts"
                    :key="shortcut.route"
                    :href="route(shortcut.route)"
                    class="flex flex-col items-center gap-2 rounded-lg border border-gray-200 bg-white p-4 text-center transition hover:border-gray-300 hover:shadow-sm dark:border-gray-800 dark:bg-gray-900 dark:hover:border-gray-700"
                >
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                        <NavIcon :name="shortcut.icon" class="h-5 w-5" />
                    </span>
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ shortcut.label }}</span>
                </Link>
            </div>
        </div>

        <div v-if="!showOrders && shortcuts.length === 0" class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $t('dashboard.welcome_title') }}</h3>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $t('dashboard.welcome_body') }}</p>
        </div>
    </AuthenticatedLayout>
</template>
