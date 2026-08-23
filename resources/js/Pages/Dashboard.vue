<script setup>
import { computed } from 'vue';
import NavIcon from '@/Components/NavIcon.vue';
import OrderCard from '@/Components/Staff/OrderCard.vue';
import OrderAlertPopup from '@/Components/Staff/OrderAlertPopup.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatCard from '@/Components/Accounting/StatCard.vue';
import SoundUnlockNotice from '@/Components/Staff/SoundUnlockNotice.vue';
import WaiterCallBanner from '@/Components/Staff/WaiterCallBanner.vue';
import { useLiveOrders } from '@/composables/useLiveOrders';
import { useMoney } from '@/composables/useMoney';
import { useOrderAlerts } from '@/composables/useOrderAlerts';
import { useWaiterCalls } from '@/composables/useWaiterCalls';
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
    showMoney: {
        type: Boolean,
        default: false,
    },
    money: {
        type: Object,
        default: null,
    },
    waiterCalls: {
        type: Array,
        default: () => [],
    },
});

const { t } = useI18n();
const page = usePage();

const roles = computed(() => page.props.auth.roles ?? []);
const isAdmin = computed(() => roles.value.includes('admin'));
const hasRole = (role) => roles.value.includes(role) || isAdmin.value;

const { money, signed } = useMoney();

const { orders } = props.showOrders ? useLiveOrders(() => props.orders) : { orders: [] };

// The service chime here too, so an admin watching the dashboard hears a
// table calling. Gated on showOrders: a user who cannot be shown an order
// has no business hearing which table is asking for one, and the Reverb
// channel would refuse their subscription anyway.
const { calls } = props.showOrders ? useWaiterCalls(props.waiterCalls) : { calls: [] };

// And the order alert on the same gate, for the same reason: an admin
// watching this dashboard is often the only person at a screen, and the
// list quietly gaining a row is not something anyone notices.
const {
    alert: orderAlert,
    dismissAlert: dismissOrderAlert,
    pendingCount: queuedOrderAlerts,
} = props.showOrders
    ? useOrderAlerts(() => props.orders)
    : { alert: null, dismissAlert: () => {}, pendingCount: 0 };

const sortedOrders = computed(() => [...orders].sort((a, b) => new Date(b.created_at) - new Date(a.created_at)));

/**
 * The screens people open, split by how often they open them.
 *
 * These were one flat grid of twelve, which meant the till — opened every
 * few minutes — sat in the same row and at the same weight as the language
 * settings, opened perhaps twice a year. On a tablet that is twelve
 * equally-loud targets to read past when you already knew which one you
 * wanted before the page loaded.
 *
 * `group: 'work'` is the handful somebody stands in front of all evening.
 * Everything else is administration, and it goes below, smaller.
 */
const shortcuts = computed(() => [
    { label: t('nav.orders'), icon: 'clipboardList', route: 'admin.orders.index', group: 'work', visible: isAdmin.value },
    { label: t('waiter.new_order'), icon: 'clipboardList', route: 'waiter.orders.create', group: 'work', visible: hasRole('waiter') },
    { label: t('nav.waiter'), icon: 'users', route: 'waiter.index', group: 'work', visible: hasRole('waiter') },
    { label: t('nav.cashier'), icon: 'cash', route: 'cashier.index', group: 'work', visible: hasRole('cashier') },
    { label: t('nav.kitchen'), icon: 'fire', route: 'kitchen.index', group: 'work', visible: hasRole('kitchen') },
    { label: t('nav.tables'), icon: 'table', route: 'admin.tables.index', visible: isAdmin.value },
    { label: t('nav.menus'), icon: 'book', route: 'admin.menus.index', visible: isAdmin.value },
    { label: t('nav.categories'), icon: 'grid', route: 'admin.categories.index', visible: isAdmin.value },
    { label: t('nav.products'), icon: 'tag', route: 'admin.products.index', visible: isAdmin.value },
    { label: t('nav.printers'), icon: 'printer', route: 'admin.printers.index', visible: isAdmin.value },
    { label: t('nav.settings'), icon: 'settings', route: 'admin.settings.edit', visible: isAdmin.value },
    { label: t('nav.languages'), icon: 'languages', route: 'admin.languages.index', visible: isAdmin.value },
].filter((item) => item.visible));

const workShortcuts = computed(() => shortcuts.value.filter((item) => item.group === 'work'));
const manageShortcuts = computed(() => shortcuts.value.filter((item) => item.group !== 'work'));
</script>

<template>
    <Head :title="$t('nav.dashboard')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('nav.dashboard') }}</template>

        <!-- Above the money, deliberately: a table waiting for someone is
             the only thing on this page that gets worse while it is
             ignored. -->
        <SoundUnlockNotice />
        <WaiterCallBanner :calls="calls" />

        <OrderAlertPopup
            :alert="orderAlert"
            :pending-count="queuedOrderAlerts"
            @dismiss="dismissOrderAlert"
        />


        <!-- The day's money, above the orders: it is the question the owner
             opens this page to answer. -->
        <div v-if="showMoney && money" class="mb-8">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ $t('accounting.cashbox') }}
                </h3>
                <Link :href="route('admin.accounting.cashbox')" class="text-xs font-medium" style="color: var(--brand-primary)">
                    {{ $t('accounting.cashbox') }} →
                </Link>
            </div>

            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard :label="$t('accounting.current_balance')" :value="money(props.money.balance_cents)" tone="balance" icon="💰" />
                <StatCard :label="$t('accounting.collected_today')" :value="signed(props.money.collected_cents)" tone="in" icon="📥" />
                <StatCard :label="$t('accounting.expenses_today')" :value="signed(-props.money.expenses_cents)" tone="out" icon="📤" />
                <StatCard :label="$t('accounting.staff_deductions')" :value="money(props.money.staff_deductions_cents)" tone="warn" icon="🏷️" />
            </div>

            <div class="mt-3 grid grid-cols-2 gap-3 lg:grid-cols-3">
                <StatCard :label="$t('accounting.net_movement')" :value="signed(props.money.net_movement_cents)" tone="neutral" icon="📊" />
                <StatCard :label="$t('accounting.absences_count')" :value="String(props.money.absences_count)" tone="neutral" icon="👤" />
                <!-- Only once the drawer has been counted: before that there
                     is nothing to compare against, and a zero would read as
                     "balanced" when nobody has looked yet. -->
                <StatCard
                    v-if="props.money.difference_state"
                    :label="$t(`accounting.difference_${props.money.difference_state}`)"
                    :value="signed(props.money.difference_cents)"
                    :tone="props.money.difference_state === 'balanced' ? 'balance' : 'warn'"
                    icon="⚠️"
                />
            </div>
        </div>

        <!-- The screens people actually stand in front of, above the order
             list rather than below it.
             
             They used to sit at the very bottom: an admin landing here had
             to scroll past seven money cards and every active order — three
             to a row, so a busy evening is seven or eight rows of them —
             before reaching the button for the screen they opened the
             dashboard to get to. The thing you came to look at should not
             be in front of the thing you came to do.
             
             Bigger targets than the administration grid below, because
             these are the ones tapped with a thumb, in a hurry, on a tablet
             propped next to a till. -->
        <div v-if="workShortcuts.length > 0" class="mb-8">
            <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ $t('dashboard.work_screens') }}
            </h3>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                <Link
                    v-for="shortcut in workShortcuts"
                    :key="shortcut.route"
                    :href="route(shortcut.route)"
                    class="flex min-h-24 flex-col items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white p-4 text-center transition hover:border-gray-300 hover:shadow-sm dark:border-gray-800 dark:bg-gray-900 dark:hover:border-gray-700"
                >
                    <span class="flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200">
                        <NavIcon :name="shortcut.icon" class="h-6 w-6" />
                    </span>
                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ shortcut.label }}</span>
                </Link>
            </div>
        </div>

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

        <!-- Administration: the screens you open when something needs
             changing, not when something needs doing. Below the orders and
             visually quieter, because reaching for "languages" is never the
             reason anybody opened this page. -->
        <div v-if="manageShortcuts.length > 0" :class="showOrders ? 'mt-8' : ''">
            <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ $t('dashboard.manage') }}
            </h3>
            <div class="grid grid-cols-3 gap-2 sm:grid-cols-4 lg:grid-cols-6">
                <Link
                    v-for="shortcut in manageShortcuts"
                    :key="shortcut.route"
                    :href="route(shortcut.route)"
                    class="flex min-h-20 flex-col items-center justify-center gap-1.5 rounded-lg border border-gray-200 bg-white p-3 text-center transition hover:border-gray-300 hover:shadow-sm dark:border-gray-800 dark:bg-gray-900 dark:hover:border-gray-700"
                >
                    <NavIcon :name="shortcut.icon" class="h-5 w-5 text-gray-500 dark:text-gray-400" />
                    <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ shortcut.label }}</span>
                </Link>
            </div>
        </div>

        <div v-if="!showOrders && shortcuts.length === 0" class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $t('dashboard.welcome_title') }}</h3>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $t('dashboard.welcome_body') }}</p>
        </div>
    </AuthenticatedLayout>
</template>
