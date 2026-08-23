<script setup>
import { computed, ref } from 'vue';
import Dropdown from '@/Components/Dropdown.vue';
import FlashToast from '@/Components/FlashToast.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import LanguageSwitcher from '@/Components/LanguageSwitcher.vue';
import NavIcon from '@/Components/NavIcon.vue';
import PendingOrdersDock from '@/Components/Staff/PendingOrdersDock.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const page = usePage();
const { t } = useI18n();
const roles = computed(() => page.props.auth.roles ?? []);
const isAdmin = computed(() => roles.value.includes('admin'));
const hasRole = (role) => roles.value.includes(role) || isAdmin.value;

// The money screens are gated per permission rather than per role, because
// a cashier belongs on some of them and not others. Hiding an entry someone
// would only be turned away from keeps the sidebar honest; the routes do
// the actual guarding.
const permissions = computed(() => page.props.auth?.permissions ?? []);
const can = (permission) => permissions.value.includes(permission);

const sidebarOpen = ref(false);
const collapsed = ref(typeof localStorage !== 'undefined' && localStorage.getItem('sidebar-collapsed') === '1');

function toggleCollapsed() {
    collapsed.value = !collapsed.value;
    localStorage.setItem('sidebar-collapsed', collapsed.value ? '1' : '0');
}

const navGroups = computed(() => [
    {
        items: [
            { label: t('nav.dashboard'), icon: 'home', route: 'dashboard', visible: true },
        ],
    },
    {
        title: t('nav.group_floor'),
        items: [
            { label: t('nav.orders'), icon: 'clipboardList', route: 'admin.orders.index', visible: isAdmin.value },
            { label: t('nav.feedback'), icon: 'note', route: 'admin.feedback.index', visible: isAdmin.value },
            { label: t('nav.accounting'), icon: 'cash', route: 'admin.accounting.index', visible: isAdmin.value },
            { label: t('nav.tables'), icon: 'table', route: 'admin.tables.index', visible: isAdmin.value },
            { label: t('nav.waiter'), icon: 'users', route: 'waiter.index', visible: hasRole('waiter') },
            { label: t('nav.cashier'), icon: 'cash', route: 'cashier.index', visible: hasRole('cashier') },
            { label: t('nav.kitchen'), icon: 'fire', route: 'kitchen.index', visible: hasRole('kitchen') },
        ],
    },
    {
        title: t('nav.accounting_group'),
        items: [
            { label: t('nav.cashbox'), icon: 'cash', route: 'admin.accounting.cashbox', visible: can('cashbox.view') },
            { label: t('nav.expenses'), icon: 'clipboardList', route: 'admin.accounting.expenses.index', visible: can('expenses.view') },
            { label: t('nav.absences'), icon: 'users', route: 'admin.accounting.absences.index', visible: can('absences.view') },
            { label: t('nav.staff_accounts'), icon: 'users', route: 'admin.accounting.staff.index', visible: can('staff_accounts.view') },
            { label: t('nav.acct_reports'), icon: 'book', route: 'admin.accounting.reports', visible: can('reports.view') },
        ],
    },
    {
        title: t('nav.group_catalog'),
        items: [
            { label: t('nav.menus'), icon: 'book', route: 'admin.menus.index', visible: isAdmin.value },
            { label: t('nav.categories'), icon: 'grid', route: 'admin.categories.index', visible: isAdmin.value },
            { label: t('nav.products'), icon: 'tag', route: 'admin.products.index', visible: isAdmin.value },
            { label: t('nav.modifier_groups'), icon: 'sliders', route: 'admin.modifier-groups.index', visible: isAdmin.value },
            { label: t('nav.tags'), icon: 'tag', route: 'admin.tags.index', visible: isAdmin.value },
            { label: t('nav.services'), icon: 'bell', route: 'admin.services.index', visible: isAdmin.value },
            { label: t('nav.service_charges'), icon: 'clock', route: 'admin.service-charges.index', visible: isAdmin.value },
        ],
    },
    {
        title: t('nav.group_system'),
        items: [
            { label: t('nav.users'), icon: 'users', route: 'admin.users.index', visible: isAdmin.value },
            { label: t('nav.languages'), icon: 'languages', route: 'admin.languages.index', visible: isAdmin.value },
            { label: t('nav.printers'), icon: 'printer', route: 'admin.printers.index', visible: isAdmin.value },
            { label: t('nav.settings'), icon: 'settings', route: 'admin.settings.edit', visible: isAdmin.value },
        ],
    },
].map((group) => ({ ...group, items: group.items.filter((item) => item.visible) })).filter((group) => group.items.length > 0));

/**
 * The one nav item the current page belongs to.
 *
 * This used to drop the last segment of the item's route name and match
 * `<the rest>.*`, meaning to keep a list item lit while one of its records
 * is being created or edited. For a name with a single segment past its
 * group — `admin.accounting.cashbox`, `admin.accounting.reports`,
 * `admin.accounting.index` — that pattern collapses to
 * `admin.accounting.*`, which matches every accounting page there is. So
 * opening المصروفات lit المحاسبة, الصندوق, المصروفات and التقارير all at
 * once, and the sidebar stopped answering the one question it exists to
 * answer: where am I?
 *
 * The broadening is kept, but only where it was meant to apply: a list
 * page's own create/edit/show screens. Everything else is an exact match.
 */
const RESOURCE_ACTIONS = ['create', 'edit', 'show'];

function isActive(routeName) {
    if (route().current(routeName)) {
        return true;
    }

    if (!routeName.endsWith('.index')) {
        return false;
    }

    const base = routeName.slice(0, -'.index'.length);

    return RESOURCE_ACTIONS.some((action) => route().current(`${base}.${action}`));
}
</script>

<template>
    <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900">
        <!-- Mobile sidebar overlay -->
        <div
            v-show="sidebarOpen"
            class="fixed inset-0 z-40 bg-gray-900/40 lg:hidden"
            @click="sidebarOpen = false"
        ></div>

        <!-- Sidebar -->
        <aside
            class="fixed inset-y-0 start-0 z-50 shrink-0 border-e border-gray-200 bg-white transition-all duration-200 lg:static lg:translate-x-0 dark:border-gray-800 dark:bg-gray-950"
            :class="[
                sidebarOpen ? 'translate-x-0' : 'max-lg:ltr:-translate-x-full max-lg:rtl:translate-x-full',
                collapsed ? 'w-20' : 'w-64',
            ]"
        >
            <div class="flex h-16 items-center gap-2 border-b border-gray-200 px-5 dark:border-gray-800" :class="collapsed ? 'justify-center px-0' : 'justify-between'">
                <Link :href="route('dashboard')" class="flex min-w-0 items-center gap-2">
                    <img
                        v-if="page.props.branding.logo_url"
                        :src="page.props.branding.logo_url"
                        class="h-8 w-8 shrink-0 rounded-md object-cover"
                        :alt="page.props.branding.name"
                    />
                    <span
                        v-else
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-sm font-bold text-white"
                        style="background-color: var(--brand-primary)"
                    >{{ page.props.branding.name?.charAt(0) ?? 'O' }}</span>
                    <span v-show="!collapsed" class="truncate font-semibold text-gray-900 dark:text-gray-100">{{ page.props.branding.name }}</span>
                </Link>
                <button
                    v-show="!collapsed"
                    type="button"
                    class="hidden shrink-0 rounded-md p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 lg:inline-flex dark:hover:bg-gray-800 dark:hover:text-gray-200"
                    :title="$t('common.collapse_sidebar')"
                    @click="toggleCollapsed"
                >
                    <NavIcon name="chevronLeft" class="h-4 w-4 rtl:rotate-180" />
                </button>
            </div>

            <button
                v-if="collapsed"
                type="button"
                class="hidden w-full items-center justify-center border-b border-gray-200 py-2 text-gray-400 hover:bg-gray-100 hover:text-gray-700 lg:flex dark:border-gray-800 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                :title="$t('common.expand_sidebar')"
                @click="toggleCollapsed"
            >
                <NavIcon name="chevronLeft" class="h-4 w-4 rotate-180 rtl:rotate-0" />
            </button>

            <nav class="space-y-6 overflow-y-auto overflow-x-hidden px-3 py-5" style="height: calc(100vh - 4rem)">
                <div v-for="(group, index) in navGroups" :key="index">
                    <p
                        v-if="group.title && !collapsed"
                        class="px-3 pb-2 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500"
                    >
                        {{ group.title }}
                    </p>
                    <Link
                        v-for="item in group.items"
                        :key="item.route"
                        :href="route(item.route)"
                        class="mb-0.5 flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors"
                        :class="[
                            isActive(item.route)
                                ? 'text-white'
                                : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-100',
                            collapsed ? 'justify-center' : '',
                        ]"
                        :style="isActive(item.route) ? { backgroundColor: 'var(--brand-primary)' } : {}"
                        :title="collapsed ? item.label : null"
                    >
                        <NavIcon :name="item.icon" class="h-5 w-5 shrink-0" />
                        <span v-show="!collapsed" class="truncate">{{ item.label }}</span>
                    </Link>
                </div>
            </nav>
        </aside>

        <!-- Main column.
             min-w-0 is load-bearing, not tidying. A flex item defaults to
             `min-width: auto`, which means it refuses to shrink below the
             intrinsic minimum width of its own content — so a wide table, a
             long unbreakable string or a grid with one column too many does
             not scroll inside this column, it pushes the column itself past
             the edge of the screen. The whole page then scrolls sideways and
             the content nearest the start edge is cut off, which in Arabic
             is the side every card's text begins on.
             With min-w-0 the column is free to shrink to the viewport and
             the overflow is handled where it belongs, inside the element
             that is actually too wide. -->
        <div class="flex min-h-screen min-w-0 flex-1 flex-col">
            <!-- Topbar -->
            <header class="sticky top-0 z-30 flex h-16 items-center justify-between border-b border-gray-200 bg-white px-4 sm:px-6 dark:border-gray-800 dark:bg-gray-950">
                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        class="rounded-md p-2 text-gray-500 hover:bg-gray-100 lg:hidden dark:text-gray-400 dark:hover:bg-gray-800"
                        @click="sidebarOpen = !sidebarOpen"
                    >
                        <NavIcon :name="sidebarOpen ? 'close' : 'menu'" />
                    </button>
                    <div v-if="$slots.header" class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                        <slot name="header" />
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <ThemeToggle />
                    <LanguageSwitcher />

                    <Dropdown align="right" width="48">
                        <template #trigger>
                            <button
                                type="button"
                                class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
                            >
                                <span class="flex h-7 w-7 items-center justify-center rounded-full bg-gray-200 text-xs font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                                    {{ page.props.auth.user?.name?.charAt(0) }}
                                </span>
                                <span class="hidden sm:inline">{{ page.props.auth.user?.name }}</span>
                                <NavIcon name="chevronDown" class="h-4 w-4" />
                            </button>
                        </template>
                        <template #content>
                            <DropdownLink :href="route('profile.edit')">{{ $t('nav.profile') }}</DropdownLink>
                            <DropdownLink :href="route('logout')" method="post" as="button">{{ $t('nav.log_out') }}</DropdownLink>
                        </template>
                    </Dropdown>
                </div>
            </header>

            <FlashToast />

            <!-- Every staff screen, not just the ones about orders: the
                 whole point is being visible while somebody is busy with
                 something else. It renders nothing when there is nothing
                 outstanding, and nothing at all for a user with no staff
                 role. -->
            <PendingOrdersDock />


            <!-- The belt to min-w-0's braces. Anything that still turns out
                 wider than the column — a wide table, a long code block —
                 scrolls inside this element instead of dragging the whole
                 page sideways with it. -->
            <main class="min-w-0 flex-1 overflow-x-auto p-4 sm:p-6">
                <slot />
            </main>
        </div>
    </div>
</template>
