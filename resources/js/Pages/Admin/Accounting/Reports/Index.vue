<script setup>
import StatCard from '@/Components/Accounting/StatCard.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatDate, formatDateTime, useMoney } from '@/composables/useMoney';
import { Head, router } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';

/**
 * One screen, one date range, four views of it.
 *
 * Everything is already on the page — switching tabs is not a fetch — so
 * comparing "the month's expenses" against "the month's cash" is
 * instantaneous and cannot end up showing two different ranges.
 */
const props = defineProps({
    filters: { type: Object, required: true },
    staff: { type: Array, required: true },
    expenses: { type: Object, required: true },
    absences: { type: Object, required: true },
    cashbox: { type: Object, required: true },
    orders: { type: Object, required: true },
    products: { type: Object, required: true },
    tables: { type: Object, required: true },
    ledger: { type: Array, required: true },
    staffReport: { type: Object, default: null },
});

const { money, signed } = useMoney();

// The cash box tab shows totals by default and the ledger on request.
// Both answer a different question — "did the day balance" versus "where
// did the 500 go" — and putting the second behind a toggle keeps the
// first readable, which is the one glanced at most.
const showLedger = ref(false);

/**
 * "Best selling" is two different questions and the answers disagree.
 *
 * Water outsells everything by units and earns almost nothing; a mixed
 * grill sells a handful and carries the night. Sorting one way and calling
 * it "the answer" hides whichever half the owner needed — so both are one
 * tap apart, and the column being sorted on is the one marked.
 *
 * Sorted on the client because every row is already here: the server sends
 * the whole list, and a round-trip to reorder forty rows would be slower
 * than the reorder.
 */
const productSort = ref('quantity');

const sortedProducts = computed(() => {
    const rows = [...(props.products?.rows ?? [])];

    return rows.sort((a, b) => (productSort.value === 'quantity'
        ? b.quantity - a.quantity
        : b.gross_cents - a.gross_cents));
});

/**
 * The widest bar is the top row, whatever is being sorted on — so the bars
 * always fill the column rather than all bunching at the left on a day when
 * nothing sold much.
 */
const productBarMax = computed(() => {
    const rows = sortedProducts.value;

    if (rows.length === 0) return 1;

    return productSort.value === 'quantity'
        ? Math.max(...rows.map((r) => r.quantity), 1)
        : Math.max(...rows.map((r) => r.gross_cents), 1);
});

function productBarWidth(row) {
    const value = productSort.value === 'quantity' ? row.quantity : row.gross_cents;

    return `${Math.max(2, (value / productBarMax.value) * 100)}%`;
}

/**
 * The head of the products ranking, for the "top" tab.
 *
 * Cut from the products tab's own rows rather than sent separately, so the
 * two tabs cannot name different best sellers. By units: "what sells the
 * most" asked without qualification means how many went out, and the
 * products tab is one tap away for the money view.
 */
const TOP_PRODUCTS = 10;

const topProducts = computed(() => [...(props.products?.rows ?? [])]
    .sort((a, b) => b.quantity - a.quantity)
    .slice(0, TOP_PRODUCTS));

const topProductMax = computed(() => Math.max(1, ...topProducts.value.map((row) => row.quantity)));

// Tables nobody sat at come after the ranking, not inside it — they are the
// answer to a different question ("which corner is dead") and a bar of
// zero width beside a rank number reads as a rendering fault.
const usedTables = computed(() => (props.tables?.rows ?? []).filter((row) => !row.is_idle));
const idleTables = computed(() => (props.tables?.rows ?? []).filter((row) => row.is_idle));

const tableMax = computed(() => Math.max(1, ...usedTables.value.map((row) => row.visits)));

function barWidth(value, max) {
    return `${Math.max(2, (value / max) * 100)}%`;
}

// The browser's own print, deliberately, rather than a server-rendered PDF.
// The report is already on the page with the range and filters the operator
// chose; asking the server to draw it again means a second template that
// has to be kept saying the same thing as the first, and the day it drifts
// the paper and the screen disagree about money.
function printReport() {
    window.print();
}

/**
 * Client-side paging for the per-order detail.
 *
 * Client-side and not server-side, unlike the admin tables: the whole
 * report is already on the page — switching tabs is not a fetch, and the
 * totals above are computed from the same range — so paging over the
 * server would fetch data the screen is already holding, and would make
 * the totals and the rows able to disagree.
 *
 * The range itself is what bounds the cost. A month of a busy cafe is
 * hundreds of rows, not tens of thousands, and the date filter above is
 * the control that keeps it that way.
 */
const ORDER_ROWS_PER_PAGE = 25;
const orderPage = ref(1);

const orderPageCount = computed(
    () => Math.max(1, Math.ceil((props.orders?.rows?.length ?? 0) / ORDER_ROWS_PER_PAGE))
);

const pagedOrderRows = computed(() => {
    const start = (orderPage.value - 1) * ORDER_ROWS_PER_PAGE;

    return (props.orders?.rows ?? []).slice(start, start + ORDER_ROWS_PER_PAGE);
});

// A new range is a new list; page 7 of the old one is meaningless and
// usually does not exist.
watch(() => props.orders, () => { orderPage.value = 1; });

// Paper gets every row. A printout that stops at row 25 with no way to
// reach row 26 is worse than no printout.
const printAllOrderRows = ref(false);

const visibleOrderRows = computed(() => (printAllOrderRows.value ? (props.orders?.rows ?? []) : pagedOrderRows.value));

onMounted(() => {
    window.addEventListener('beforeprint', () => (printAllOrderRows.value = true));
    window.addEventListener('afterprint', () => (printAllOrderRows.value = false));
});

function reload(overrides = {}) {
    const pick = (key, fallback) => (key in overrides ? overrides[key] : fallback);

    const params = {
        from: pick('from', props.filters.from),
        to: pick('to', props.filters.to),
        from_time: pick('from_time', props.filters.from_time),
        to_time: pick('to_time', props.filters.to_time),
        user_id: pick('user_id', props.filters.user_id),
        tab: pick('tab', props.filters.tab),
    };

    for (const key of Object.keys(params)) {
        if (params[key] === null || params[key] === '' || params[key] === undefined) delete params[key];
    }

    router.get(route('admin.accounting.reports'), params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

// Paper and spreadsheet carry the times too, or the file says it covers a
// day that the screen it was taken from did not.
const pdfUrl = computed(() => route('admin.accounting.reports.pdf', {
    tab: props.filters.tab,
    from: props.filters.from,
    to: props.filters.to,
    from_time: props.filters.from_time ?? undefined,
    to_time: props.filters.to_time ?? undefined,
}));

const exportUrl = computed(() => route('admin.accounting.reports.export', {
    from: props.filters.from,
    to: props.filters.to,
    from_time: props.filters.from_time ?? undefined,
    to_time: props.filters.to_time ?? undefined,
    user_id: props.filters.user_id ?? undefined,
    tab: props.filters.tab,
}));

// "23/08 10:00 — 24/08 03:00" when a time is set, and the plain dates when
// not, so the header never implies a precision the range does not have.
const periodLabel = computed(() => {
    const side = (date, time) => (time ? `${formatDate(date)} ${time}` : formatDate(date));

    return `${side(props.filters.from, props.filters.from_time)} — ${side(props.filters.to, props.filters.to_time)}`;
});

const tabs = ['expenses', 'absences', 'cashbox', 'orders', 'products', 'top', 'staff'];

const selectClass = 'focus-brand block w-full rounded-md border-gray-300 py-1.5 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300';
</script>

<template>
    <Head :title="$t('accounting.reports')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('accounting.reports') }}</template>

        <!-- Paper only. A printout with no restaurant name, no report name
             and no date range is a sheet of numbers nobody can file or
             argue with a month later. -->
        <div class="print-only mb-4 border-b border-gray-400 pb-3">
            <p class="text-lg font-bold">{{ $page.props.branding.name }}</p>
            <p class="text-sm">{{ $t(`accounting.report_${filters.tab}`) }}</p>
            <p class="text-xs">
                {{ $t('accounting.period') }}: {{ periodLabel }}
                <span aria-hidden="true"> · </span>
                {{ $t('accounting.printed_at') }}: {{ formatDateTime(new Date().toISOString()) }}
            </p>
        </div>

        <div class="mb-4 rounded-lg border border-gray-200 bg-white p-3 print:hidden dark:border-gray-800 dark:bg-gray-900">
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <!-- Date and time as one control each. A night that ends at
                     3am is one range with two dates on it, so the clock has
                     to be part of the same decision as the calendar rather
                     than a separate row further down the form. Empty time
                     means the whole day — see ResolvesDateRange. -->
                <div class="flex gap-1">
                    <TextInput :model-value="filters.from" type="date" dir="ltr" class="min-w-0 flex-1 text-sm"
                               @update:model-value="(value) => reload({ from: value })" />
                    <TextInput :model-value="filters.from_time ?? ''" type="time" dir="ltr" class="w-24 shrink-0 text-sm"
                               :title="$t('accounting.from_time')" :aria-label="$t('accounting.from_time')"
                               @update:model-value="(value) => reload({ from_time: value || null })" />
                </div>
                <div class="flex gap-1">
                    <TextInput :model-value="filters.to" type="date" dir="ltr" class="min-w-0 flex-1 text-sm"
                               @update:model-value="(value) => reload({ to: value })" />
                    <TextInput :model-value="filters.to_time ?? ''" type="time" dir="ltr" class="w-24 shrink-0 text-sm"
                               :title="$t('accounting.to_time')" :aria-label="$t('accounting.to_time')"
                               @update:model-value="(value) => reload({ to_time: value || null })" />
                </div>
                <select :class="selectClass" :value="filters.user_id ?? ''"
                        @change="reload({ user_id: $event.target.value || null })">
                    <option value="">{{ $t('accounting.all_staff') }}</option>
                    <option v-for="member in staff" :key="member.id" :value="member.id">
                        {{ member.deleted ? `${member.name} (${$t('admin.users.deleted_account')})` : member.name }}
                    </option>
                </select>
                <!-- A plain link, not an Inertia visit: the response is a
                     file, and Inertia would try to parse it as a page. -->
                <a :href="exportUrl" class="inline-flex items-center justify-center">
                    <SecondaryButton type="button" class="w-full justify-center">⬇ {{ $t('accounting.export_csv') }}</SecondaryButton>
                </a>
                <!-- Only the two tabs the PDF template covers. A button
                     that produces an empty document is worse than no
                     button. -->
                <a v-if="['orders', 'cashbox'].includes(filters.tab)" :href="pdfUrl" target="_blank" rel="noopener" class="inline-flex items-center justify-center">
                    <SecondaryButton type="button" class="w-full justify-center">🖨 {{ $t('accounting.print') }}</SecondaryButton>
                </a>
            </div>

            <!-- Said only once a time is actually set, and said then because
                 the tabs will disagree: sales narrow to the hours chosen
                 while expenses and closings still cover whole days, and an
                 operator comparing the two needs to know that is the data
                 and not the filter failing. -->
            <p v-if="filters.from_time || filters.to_time"
               class="mt-2 text-[11px] leading-relaxed text-gray-500 dark:text-gray-400">
                {{ $t('accounting.time_hint') }}
            </p>
        </div>

        <div class="mb-4 flex flex-wrap gap-1 border-b border-gray-200 print:hidden dark:border-gray-700">
            <button v-for="tab in tabs" :key="tab" type="button"
                    class="border-b-2 px-4 py-2 text-sm font-medium transition"
                    :class="filters.tab === tab ? 'border-transparent' : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'"
                    :style="filters.tab === tab ? { borderBottomColor: 'var(--brand-primary)', color: 'var(--brand-primary)' } : {}"
                    @click="reload({ tab })">
                {{ $t(`accounting.report_${tab}`) }}
            </button>
        </div>

        <!-- Expenses -->
        <div v-if="filters.tab === 'expenses'">
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-3">
                <StatCard :label="$t('accounting.range_total')" :value="money(expenses.total_cents)" tone="out" />
                <StatCard :label="$t('accounting.paid_from_cash')" :value="money(expenses.cash_cents)" tone="neutral" />
                <StatCard :label="$t('accounting.count')" :value="String(expenses.count)" tone="neutral" />
            </div>

            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                    <h4 class="mb-3 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $t('accounting.by_category') }}</h4>
                    <div v-for="row in expenses.by_category" :key="row.name" class="mb-2 flex items-center gap-3">
                        <span class="w-32 shrink-0 truncate text-sm text-gray-700 dark:text-gray-300">{{ row.name }}</span>
                        <!-- The bar is proportional to the largest row, so
                             the shape of the month is readable at a glance
                             without anyone reading the numbers. -->
                        <div class="h-2 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                            <div class="h-full rounded-full"
                                 :style="{
                                     width: `${expenses.total_cents ? (row.total_cents / expenses.by_category[0].total_cents) * 100 : 0}%`,
                                     backgroundColor: 'var(--brand-primary)',
                                 }" />
                        </div>
                        <span class="w-24 shrink-0 text-end text-sm tabular-nums text-gray-600 dark:text-gray-400" dir="ltr">
                            {{ money(row.total_cents) }}
                        </span>
                    </div>
                </div>

                <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
                        <thead class="bg-gray-50 dark:bg-gray-900/60">
                            <tr class="text-xs uppercase text-gray-500">
                                <th class="px-3 py-2 text-start">{{ $t('accounting.date') }}</th>
                                <th class="px-3 py-2 text-start">{{ $t('accounting.category') }}</th>
                                <th class="px-3 py-2 text-start">{{ $t('accounting.staff_member') }}</th>
                                <th class="px-3 py-2 text-end">{{ $t('accounting.amount') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <tr v-for="row in expenses.rows" :key="row.id">
                                <td class="whitespace-nowrap px-3 py-2 tabular-nums text-gray-500" dir="ltr">{{ formatDate(row.date) }}</td>
                                <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ row.category }}</td>
                                <td class="px-3 py-2 text-gray-500">{{ row.staff ?? '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-end tabular-nums text-red-600 dark:text-red-400" dir="ltr">
                                    {{ money(row.amount_cents) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Absences -->
        <div v-else-if="filters.tab === 'absences'">
            <div class="grid grid-cols-2 gap-3">
                <StatCard :label="$t('accounting.absences_count')" :value="String(absences.count)" tone="neutral" />
                <StatCard :label="$t('accounting.total_deductions')" :value="money(absences.deductions_cents)" tone="warn" />
            </div>

            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                    <h4 class="border-b border-gray-200 px-4 py-3 text-sm font-semibold dark:border-gray-800">{{ $t('accounting.by_employee') }}</h4>
                    <table class="min-w-full divide-y divide-gray-100 text-sm dark:divide-gray-800">
                        <tbody>
                            <tr v-for="row in absences.by_employee" :key="row.name">
                                <td class="px-4 py-2 text-gray-700 dark:text-gray-300">{{ row.name }}</td>
                                <td class="px-4 py-2 text-gray-500">{{ row.count }}</td>
                                <td class="px-4 py-2 text-end tabular-nums text-amber-600 dark:text-amber-400" dir="ltr">
                                    {{ money(row.deductions_cents) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                    <h4 class="border-b border-gray-200 px-4 py-3 text-sm font-semibold dark:border-gray-800">{{ $t('accounting.by_category') }}</h4>
                    <table class="min-w-full divide-y divide-gray-100 text-sm dark:divide-gray-800">
                        <tbody>
                            <tr v-for="row in absences.by_category" :key="row.name">
                                <td class="px-4 py-2 text-gray-700 dark:text-gray-300">{{ row.name }}</td>
                                <td class="px-4 py-2 text-gray-500">{{ row.count }}</td>
                                <td class="px-4 py-2 text-end tabular-nums text-amber-600 dark:text-amber-400" dir="ltr">
                                    {{ money(row.deductions_cents) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- The day's trade. Deliberately not the same question as the cash
             box beside it: this is what was sold, that is what is in the
             drawer, and a card payment or a table still open makes them
             different numbers. -->
        <div v-else-if="filters.tab === 'products'">
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-3">
                <StatCard :label="$t('accounting.units_sold')" :value="String(products.totals.quantity)" tone="neutral" />
                <StatCard :label="$t('accounting.gross')" :value="money(products.totals.gross_cents)" tone="in" />
                <StatCard :label="$t('accounting.products_sold')" :value="String(products.totals.products_count)" tone="neutral" />
            </div>

            <!-- Sections before line items: the shape of the trade before
                 the detail of it. Which half of the menu is carrying the
                 other is the question an owner asks first. -->
            <template v-if="products.by_category.length">
                <h3 class="mb-2 mt-6 text-sm font-semibold text-gray-700 dark:text-gray-300">{{ $t('accounting.by_section') }}</h3>
                <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
                        <thead class="bg-gray-50 dark:bg-gray-900/60">
                            <tr class="text-xs uppercase text-gray-500">
                                <th class="px-3 py-2 text-start">{{ $t('accounting.section') }}</th>
                                <th class="px-3 py-2 text-end">{{ $t('accounting.units_sold') }}</th>
                                <th class="px-3 py-2 text-end">{{ $t('accounting.gross') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <tr v-for="row in products.by_category" :key="row.category_id ?? 'none'">
                                <td class="px-3 py-2">{{ row.name ?? $t('accounting.no_section') }}</td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ row.quantity }}</td>
                                <td class="px-3 py-2 text-end font-semibold tabular-nums">{{ money(row.gross_cents) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <div class="mb-2 mt-6 flex flex-wrap items-center justify-between gap-3">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ $t('accounting.best_sellers') }}</h3>

                <!-- Two questions, one tap apart. Hidden on paper: the
                     printout is already sorted the way it was asked for. -->
                <div class="flex gap-1 rounded-lg bg-gray-100 p-1 dark:bg-gray-800 print:hidden">
                    <button
                        v-for="option in ['quantity', 'revenue']"
                        :key="option"
                        type="button"
                        class="rounded-md px-3 py-1 text-xs font-medium transition"
                        :class="productSort === option
                            ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-900 dark:text-gray-100'
                            : 'text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'"
                        @click="productSort = option"
                    >
                        {{ option === 'quantity' ? $t('accounting.sort_by_quantity') : $t('accounting.sort_by_revenue') }}
                    </button>
                </div>
            </div>

            <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
                    <thead class="bg-gray-50 dark:bg-gray-900/60">
                        <tr class="text-xs uppercase text-gray-500">
                            <th class="w-10 px-3 py-2 text-end">#</th>
                            <th class="px-3 py-2 text-start">{{ $t('accounting.product') }}</th>
                            <th class="px-3 py-2 text-end" :class="productSort === 'quantity' && 'text-gray-900 dark:text-gray-200'">
                                {{ $t('accounting.units_sold') }}
                            </th>
                            <th class="px-3 py-2 text-end">{{ $t('accounting.bills') }}</th>
                            <th class="px-3 py-2 text-end" :class="productSort === 'revenue' && 'text-gray-900 dark:text-gray-200'">
                                {{ $t('accounting.gross') }}
                            </th>
                            <th class="px-3 py-2 text-end">{{ $t('accounting.share_of_sales') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <tr v-for="(row, index) in sortedProducts" :key="row.product_id ?? row.name">
                            <td class="px-3 py-2 text-end text-xs tabular-nums text-gray-400">{{ index + 1 }}</td>
                            <td class="px-3 py-2">
                                {{ row.name }}
                                <!-- The bar is the ranking made readable: a
                                     column of numbers tells you the order,
                                     this tells you the gap. -->
                                <div class="mt-1 h-1 w-full max-w-[14rem] overflow-hidden rounded-full bg-gray-100 print:hidden dark:bg-gray-800">
                                    <div class="h-full rounded-full" :style="{ width: productBarWidth(row), backgroundColor: 'var(--brand-primary)' }"></div>
                                </div>
                            </td>
                            <td class="px-3 py-2 text-end tabular-nums" :class="productSort === 'quantity' && 'font-semibold'">{{ row.quantity }}</td>
                            <td class="px-3 py-2 text-end text-xs tabular-nums text-gray-500">{{ row.orders_count }}</td>
                            <td class="px-3 py-2 text-end tabular-nums" :class="productSort === 'revenue' && 'font-semibold'">{{ money(row.gross_cents) }}</td>
                            <td class="px-3 py-2 text-end text-xs tabular-nums text-gray-500">{{ row.share }}%</td>
                        </tr>
                        <tr v-if="sortedProducts.length === 0">
                            <td colspan="6" class="px-3 py-6 text-center text-gray-500">{{ $t('accounting.no_movements') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Said here rather than left to be discovered: this tab and
                 the orders tab report different numbers on purpose. -->
            <p class="mt-3 text-xs leading-relaxed text-gray-500 dark:text-gray-400">{{ $t('accounting.gross_note') }}</p>
        </div>

        <!-- Best sellers and busiest tables, side by side in one place. -->
        <div v-else-if="filters.tab === 'top'">
            <!-- The two answers in a sentence each before any table: most
                 people opening this tab want exactly these two names. -->
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/50 dark:bg-amber-950/30">
                    <p class="text-xs font-medium text-gray-600 dark:text-gray-400">🏆 {{ $t('accounting.top_product') }}</p>
                    <template v-if="topProducts.length">
                        <p class="mt-1.5 text-xl font-bold text-gray-900 dark:text-gray-100">{{ topProducts[0].name }}</p>
                        <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                            {{ $t('accounting.sold_times', { n: topProducts[0].quantity }) }}
                            <span aria-hidden="true"> · </span>
                            <span class="tabular-nums">{{ money(topProducts[0].gross_cents) }}</span>
                        </p>
                    </template>
                    <p v-else class="mt-1.5 text-sm text-gray-500">{{ $t('accounting.no_movements') }}</p>
                </div>

                <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-900/50 dark:bg-blue-950/30">
                    <p class="text-xs font-medium text-gray-600 dark:text-gray-400">🪑 {{ $t('accounting.top_table') }}</p>
                    <template v-if="usedTables.length">
                        <p class="mt-1.5 text-xl font-bold text-gray-900 dark:text-gray-100">{{ usedTables[0].label }}</p>
                        <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                            {{ $t('accounting.used_times', { n: usedTables[0].visits }) }}
                            <span aria-hidden="true"> · </span>
                            <span class="tabular-nums">{{ money(usedTables[0].collected_cents) }}</span>
                        </p>
                    </template>
                    <p v-else class="mt-1.5 text-sm text-gray-500">{{ $t('accounting.no_movements') }}</p>
                </div>
            </div>

            <div class="mb-2 mt-6 flex flex-wrap items-center justify-between gap-3">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ $t('accounting.top_products') }}</h3>
                <button type="button" class="text-xs font-medium hover:underline print:hidden"
                        :style="{ color: 'var(--brand-primary)' }"
                        @click="reload({ tab: 'products' })">
                    {{ $t('accounting.see_all_products') }}
                </button>
            </div>

            <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
                    <thead class="bg-gray-50 dark:bg-gray-900/60">
                        <tr class="text-xs uppercase text-gray-500">
                            <th class="w-10 px-3 py-2 text-end">#</th>
                            <th class="px-3 py-2 text-start">{{ $t('accounting.product') }}</th>
                            <th class="px-3 py-2 text-end">{{ $t('accounting.units_sold') }}</th>
                            <th class="px-3 py-2 text-end">{{ $t('accounting.gross') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <tr v-for="(row, index) in topProducts" :key="row.product_id ?? row.name">
                            <td class="px-3 py-2 text-end text-xs tabular-nums text-gray-400">{{ index + 1 }}</td>
                            <td class="px-3 py-2">
                                {{ row.name }}
                                <div class="mt-1 h-1 w-full max-w-[14rem] overflow-hidden rounded-full bg-gray-100 print:hidden dark:bg-gray-800">
                                    <div class="h-full rounded-full" :style="{ width: barWidth(row.quantity, topProductMax), backgroundColor: 'var(--brand-primary)' }"></div>
                                </div>
                            </td>
                            <td class="px-3 py-2 text-end font-semibold tabular-nums">{{ row.quantity }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ money(row.gross_cents) }}</td>
                        </tr>
                        <tr v-if="topProducts.length === 0">
                            <td colspan="4" class="px-3 py-6 text-center text-gray-500">{{ $t('accounting.no_movements') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <h3 class="mb-2 mt-6 text-sm font-semibold text-gray-700 dark:text-gray-300">{{ $t('accounting.top_tables') }}</h3>

            <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
                    <thead class="bg-gray-50 dark:bg-gray-900/60">
                        <tr class="text-xs uppercase text-gray-500">
                            <th class="w-10 px-3 py-2 text-end">#</th>
                            <th class="px-3 py-2 text-start">{{ $t('tables.title') }}</th>
                            <th class="px-3 py-2 text-end">{{ $t('accounting.times_used') }}</th>
                            <th class="px-3 py-2 text-end">{{ $t('accounting.guests') }}</th>
                            <th class="px-3 py-2 text-end">{{ $t('accounting.collected') }}</th>
                            <th class="px-3 py-2 text-end">{{ $t('accounting.average_ticket') }}</th>
                            <th class="px-3 py-2 text-end">{{ $t('accounting.typical_stay') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <tr v-for="(row, index) in usedTables" :key="row.key">
                            <td class="px-3 py-2 text-end text-xs tabular-nums text-gray-400">{{ index + 1 }}</td>
                            <td class="px-3 py-2">
                                {{ row.label }}
                                <div class="mt-1 h-1 w-full max-w-[14rem] overflow-hidden rounded-full bg-gray-100 print:hidden dark:bg-gray-800">
                                    <div class="h-full rounded-full" :style="{ width: barWidth(row.visits, tableMax), backgroundColor: 'var(--brand-primary)' }"></div>
                                </div>
                            </td>
                            <td class="px-3 py-2 text-end font-semibold tabular-nums">
                                {{ row.visits }}
                                <span class="ms-1 text-xs font-normal text-gray-400">{{ row.share }}%</span>
                            </td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ row.guests ?? '—' }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ money(row.collected_cents) }}</td>
                            <td class="px-3 py-2 text-end tabular-nums text-gray-600 dark:text-gray-400">{{ money(row.average_cents) }}</td>
                            <td class="px-3 py-2 text-end text-xs tabular-nums text-gray-600 dark:text-gray-400">
                                <template v-if="row.typical_minutes === null">—</template>
                                <template v-else-if="row.typical_minutes < 60">{{ $t('accounting.duration_minutes', { m: row.typical_minutes }) }}</template>
                                <template v-else>{{ $t('accounting.duration_hours', { h: Math.floor(row.typical_minutes / 60), m: row.typical_minutes % 60 }) }}</template>
                            </td>
                        </tr>
                        <tr v-for="row in idleTables" :key="row.key" class="text-gray-400">
                            <td class="px-3 py-2"></td>
                            <td class="px-3 py-2">{{ row.label }}</td>
                            <td colspan="5" class="px-3 py-2 text-end text-xs">{{ $t('accounting.table_idle') }}</td>
                        </tr>
                        <tr v-if="usedTables.length === 0 && idleTables.length === 0">
                            <td colspan="7" class="px-3 py-6 text-center text-gray-500">{{ $t('accounting.no_movements') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-if="tables.takeaway.visits > 0" class="mt-3 text-xs leading-relaxed text-gray-600 dark:text-gray-400">
                {{ $t('accounting.takeaway_note', { n: tables.takeaway.visits, amount: money(tables.takeaway.collected_cents) }) }}
            </p>
            <p class="mt-2 text-xs leading-relaxed text-gray-500 dark:text-gray-400">{{ $t('accounting.tables_note') }}</p>
        </div>

        <div v-else-if="filters.tab === 'orders'">
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard :label="$t('accounting.orders_count')" :value="String(orders.totals.orders_count)" tone="neutral" />
                <StatCard :label="$t('accounting.collected')" :value="money(orders.totals.collected_cents)" tone="in" />
                <StatCard :label="$t('accounting.discounts')" :value="money(orders.totals.discount_cents)" tone="warn" />
                <StatCard :label="$t('accounting.average_ticket')" :value="money(orders.totals.average_cents)" tone="neutral" />
            </div>

            <!-- Per day first: a range total nobody can check against a till
                 roll is a number they have to take on faith. -->
            <div class="mt-4 overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
                    <thead class="bg-gray-50 dark:bg-gray-900/60">
                        <tr class="text-xs uppercase text-gray-500">
                            <th class="px-3 py-2 text-start">{{ $t('accounting.date') }}</th>
                            <th class="px-3 py-2 text-end">{{ $t('accounting.orders_count') }}</th>
                            <th class="px-3 py-2 text-end">{{ $t('accounting.gross') }}</th>
                            <th class="px-3 py-2 text-end">{{ $t('accounting.discounts') }}</th>
                            <th class="px-3 py-2 text-end">{{ $t('accounting.collected') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <tr v-for="day in orders.by_day" :key="day.date">
                            <td class="px-3 py-2">{{ formatDate(day.date) }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ day.orders_count }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ money(day.gross_cents) }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ money(day.discount_cents) }}</td>
                            <td class="px-3 py-2 text-end font-semibold tabular-nums">{{ money(day.collected_cents) }}</td>
                        </tr>
                        <tr v-if="orders.by_day.length === 0">
                            <td colspan="5" class="px-3 py-6 text-center text-gray-500">{{ $t('accounting.no_movements') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Then every bill, so a figure above can be traced to the
                 table it came from. -->
            <h3 class="mb-2 mt-6 text-sm font-semibold text-gray-700 dark:text-gray-300">{{ $t('accounting.detailed') }}</h3>
            <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
                    <thead class="bg-gray-50 dark:bg-gray-900/60">
                        <tr class="text-xs uppercase text-gray-500">
                            <th class="px-3 py-2 text-start">{{ $t('accounting.order_ref') }}</th>
                            <th class="px-3 py-2 text-start">{{ $t('tables.title') }}</th>
                            <th class="px-3 py-2 text-start">{{ $t('accounting.paid_at') }}</th>
                            <th class="px-3 py-2 text-end">{{ $t('accounting.items') }}</th>
                            <th class="px-3 py-2 text-end">{{ $t('accounting.discounts') }}</th>
                            <th class="px-3 py-2 text-end">{{ $t('accounting.collected') }}</th>
                            <th class="px-3 py-2 text-start">{{ $t('accounting.by') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <tr v-for="row in visibleOrderRows" :key="row.id">
                            <td class="px-3 py-2 font-mono text-xs">{{ row.reference }}</td>
                            <td class="px-3 py-2">{{ row.table ?? '—' }}</td>
                            <td class="px-3 py-2 text-xs text-gray-600 dark:text-gray-400">{{ formatDateTime(row.paid_at) }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ row.items_count }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ money(row.discount_cents) }}</td>
                            <td class="px-3 py-2 text-end font-semibold tabular-nums">{{ money(row.collected_cents) }}</td>
                            <td class="px-3 py-2 text-xs text-gray-600 dark:text-gray-400">{{ row.by ?? '—' }}</td>
                        </tr>
                        <tr v-if="orders.rows.length === 0">
                            <td colspan="7" class="px-3 py-6 text-center text-gray-500">{{ $t('accounting.no_movements') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Only when there is more than a page of them. -->
            <div v-if="orderPageCount > 1" class="mt-3 flex flex-wrap items-center justify-between gap-3 print:hidden">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ $t('pagination.showing', {
                        from: (orderPage - 1) * 25 + 1,
                        to: Math.min(orderPage * 25, orders.rows.length),
                        total: orders.rows.length,
                    }) }}
                </p>

                <div class="flex items-center gap-2">
                    <SecondaryButton type="button" :disabled="orderPage <= 1" @click="orderPage -= 1">
                        {{ $t('pagination.previous') }}
                    </SecondaryButton>
                    <span class="text-sm tabular-nums text-gray-600 dark:text-gray-400">
                        {{ $t('pagination.page_of', { page: orderPage, last: orderPageCount }) }}
                    </span>
                    <SecondaryButton type="button" :disabled="orderPage >= orderPageCount" @click="orderPage += 1">
                        {{ $t('pagination.next') }}
                    </SecondaryButton>
                </div>
            </div>
        </div>

        <!-- Cash box, day by day -->
        <div v-else-if="filters.tab === 'cashbox'">
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard :label="$t('accounting.collected_today')" :value="money(cashbox.totals.collected_cents)" tone="in" />
                <StatCard :label="$t('accounting.expenses_today')" :value="money(cashbox.totals.expenses_cents)" tone="out" />
                <StatCard :label="$t('accounting.staff_deductions')" :value="money(cashbox.totals.staff_deductions_cents)" tone="warn" />
                <StatCard :label="$t('accounting.difference')" :value="signed(cashbox.totals.difference_cents)"
                          :tone="cashbox.totals.difference_cents === 0 ? 'balance' : 'warn'" />
            </div>

            <p v-if="cashbox.truncated" class="mt-2 text-xs text-amber-600 dark:text-amber-400">
                {{ $t('accounting.truncated_range') }}
            </p>

            <div class="mt-4 overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
                    <thead class="bg-gray-50 dark:bg-gray-900/60">
                        <tr class="text-xs uppercase text-gray-500">
                            <th class="px-3 py-2 text-start">{{ $t('accounting.date') }}</th>
                            <th class="px-3 py-2 text-end">{{ $t('accounting.opening_balance') }}</th>
                            <th class="px-3 py-2 text-end">{{ $t('accounting.total_in') }}</th>
                            <th class="px-3 py-2 text-end">{{ $t('accounting.total_out') }}</th>
                            <th class="px-3 py-2 text-end">{{ $t('accounting.current_balance') }}</th>
                            <th class="px-3 py-2 text-end">{{ $t('accounting.difference') }}</th>
                            <th class="px-3 py-2 text-start">{{ $t('common.status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <tr v-for="day in cashbox.days" :key="day.date">
                            <td class="whitespace-nowrap px-3 py-2 tabular-nums text-gray-700 dark:text-gray-300" dir="ltr">{{ formatDate(day.date) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-end tabular-nums text-gray-500" dir="ltr">{{ money(day.opening_cents) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-end tabular-nums text-green-600 dark:text-green-400" dir="ltr">{{ money(day.collected_cents) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-end tabular-nums text-red-600 dark:text-red-400" dir="ltr">{{ money(day.expenses_cents) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-end tabular-nums font-semibold text-gray-900 dark:text-gray-100" dir="ltr">{{ money(day.balance_cents) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-end tabular-nums" dir="ltr"
                                :class="day.difference_cents === null ? 'text-gray-300'
                                    : (day.difference_cents === 0 ? 'text-green-600' : 'text-amber-600')">
                                {{ day.difference_cents === null ? '—' : signed(day.difference_cents) }}
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-xs">
                                <span class="inline-flex rounded-full px-2 py-0.5"
                                      :class="day.is_closed
                                          ? 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300'
                                          : 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400'">
                                    {{ day.is_closed ? $t('accounting.closed_day') : $t('accounting.open_day') }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- The ledger, on request. The table above says the balance
                 moved; this says which bill, which expense, which
                 withdrawal, in the order they happened — the question
                 actually asked when a number looks wrong. -->
            <div class="mt-4">
                <SecondaryButton type="button" @click="showLedger = !showLedger">
                    {{ showLedger ? $t('accounting.summary') : $t('accounting.ledger_title') }}
                </SecondaryButton>
            </div>

            <div v-if="showLedger" class="mt-4 space-y-4">
                <p v-if="ledger.length === 0" class="rounded-lg border border-gray-200 bg-white p-6 text-center text-sm text-gray-500 dark:border-gray-800 dark:bg-gray-900">
                    {{ $t('accounting.no_movements') }}
                </p>

                <div v-for="day in ledger" :key="day.date" class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                    <!-- Opening and closing on the header, so each block is a
                         self-contained statement: what was there, what
                         happened, what is left. -->
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-800 dark:bg-gray-900/60">
                        <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ formatDate(day.date) }}</span>
                        <span class="text-xs text-gray-600 dark:text-gray-400">
                            {{ $t('accounting.opening') }}: <span class="tabular-nums">{{ money(day.opening_cents) }}</span>
                            <span aria-hidden="true"> · </span>
                            {{ $t('accounting.closing_balance') }}: <span class="font-semibold tabular-nums">{{ money(day.balance_cents) }}</span>
                        </span>
                    </div>

                    <table class="min-w-full divide-y divide-gray-100 text-sm dark:divide-gray-800">
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <tr v-for="row in day.movements" :key="row.id">
                                <td class="w-16 px-3 py-2">
                                    <span
                                        class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="row.direction === 'in'
                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
                                            : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'"
                                    >
                                        {{ row.direction === 'in' ? $t('accounting.in') : $t('accounting.out') }}
                                    </span>
                                </td>
                                <td class="px-3 py-2">
                                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ row.label ?? '—' }}</span>
                                    <span v-if="row.description" class="block text-xs text-gray-600 dark:text-gray-400">{{ row.description }}</span>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-xs text-gray-600 dark:text-gray-400">{{ formatDateTime(row.at) }}</td>
                                <td class="px-3 py-2 text-xs text-gray-600 dark:text-gray-400">{{ row.by ?? '—' }}</td>
                                <td
                                    class="whitespace-nowrap px-3 py-2 text-end font-semibold tabular-nums"
                                    :class="row.direction === 'in' ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-700 dark:text-red-400'"
                                >
                                    {{ row.direction === 'in' ? '+' : '−' }}{{ money(row.amount_cents) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- One employee, everything -->
        <div v-else>
            <div v-if="!staffReport" class="rounded-lg border border-gray-200 bg-white p-10 text-center text-sm text-gray-400 dark:border-gray-800 dark:bg-gray-900">
                {{ $t('accounting.all_staff') }} — {{ $t('accounting.staff_member') }}
            </div>

            <template v-else>
                <h3 class="mb-3 text-base font-semibold text-gray-900 dark:text-gray-100">{{ staffReport.name }}</h3>

                <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
                    <StatCard :label="$t('accounting.total_advances')" :value="money(staffReport.summary.advances_cents)" tone="neutral" />
                    <StatCard :label="$t('accounting.total_expenses')" :value="money(staffReport.summary.expenses_cents)" tone="out" />
                    <StatCard :label="$t('accounting.total_absence_deductions')" :value="money(staffReport.summary.absence_deductions_cents)" tone="warn" />
                    <StatCard :label="$t('accounting.total_payments')" :value="money(staffReport.summary.payments_cents)" tone="in" />
                    <StatCard :label="$t('accounting.balance')" :value="money(Math.abs(staffReport.summary.balance_cents))" tone="balance" />
                </div>

                <div class="mt-4 overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
                        <thead class="bg-gray-50 dark:bg-gray-900/60">
                            <tr class="text-xs uppercase text-gray-500">
                                <th class="px-3 py-2 text-start">{{ $t('accounting.statement_date') }}</th>
                                <th class="px-3 py-2 text-start">{{ $t('accounting.statement_description') }}</th>
                                <th class="px-3 py-2 text-end">{{ $t('accounting.statement_debit') }}</th>
                                <th class="px-3 py-2 text-end">{{ $t('accounting.statement_credit') }}</th>
                                <th class="px-3 py-2 text-end">{{ $t('accounting.statement_balance') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <tr v-for="entry in staffReport.entries" :key="entry.id">
                                <td class="whitespace-nowrap px-3 py-2 tabular-nums text-gray-500" dir="ltr">{{ formatDate(entry.date) }}</td>
                                <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ entry.description }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-end tabular-nums text-red-600 dark:text-red-400" dir="ltr">
                                    {{ entry.debit_cents ? money(entry.debit_cents) : '—' }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-end tabular-nums text-green-600 dark:text-green-400" dir="ltr">
                                    {{ entry.credit_cents ? money(entry.credit_cents) : '—' }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-end tabular-nums font-semibold text-gray-900 dark:text-gray-100" dir="ltr">
                                    {{ money(entry.balance_cents) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
