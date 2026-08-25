<script setup>
import NavIcon from '@/Components/NavIcon.vue';
import { ACTIVE_STATUSES } from '@/constants/orderStatus';
import { useOrderStatus } from '@/composables/useOrderStatus';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    orders: {
        type: Array,
        required: true,
    },
    date: {
        type: String,
        required: true,
    },
    status: {
        type: String,
        default: null,
    },
    statusCounts: {
        type: Object,
        required: true,
    },
    summary: {
        type: Object,
        required: true,
    },
});

const page = usePage();
const { t } = useI18n();
const { statusBadgeClasses } = useOrderStatus();

function reload(overrides = {}) {
    router.get(route('admin.orders.index'), {
        date: overrides.date ?? props.date,
        status: 'status' in overrides ? overrides.status : props.status,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

function formatPrice(cents) {
    return `${page.props.branding.currency_symbol}${(cents / 100).toFixed(2)}`;
}

function formatTime(iso) {
    return new Date(iso).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
}

function hasNotes(order) {
    return Boolean(order.notes) || order.items.some((item) => item.notes);
}

const expandedId = ref(null);

// Which line is being edited, and the values being typed. Prices are held
// in cents everywhere else; the box shows currency units because that is
// what an administrator is reading off a bill.
const editingItemId = ref(null);
const editingPrice = ref(0);
const editingQuantity = ref(1);

// A bill nobody can add to any more: paid, or filed away as completed or
// cancelled. This screen still lets an administrator correct it — that is
// the point of the screen — but it has to say which number is moving,
// because what was collected is recorded separately and stays put.
function isClosed(order) {
    return order.is_paid || !ACTIVE_STATUSES.includes(order.status);
}

function startEditingItem(item) {
    editingItemId.value = item.id;
    editingPrice.value = Number((item.unit_price_cents / 100).toFixed(2));
    editingQuantity.value = item.quantity;
}

// What the line will come to once saved. Shown while the boxes are open,
// because a quantity typed into a box and a total printed two columns away
// look unrelated: raising the quantity and watching the price sit still
// reads as the edit having been ignored.
const editingSubtotalCents = computed(() => {
    const cents = Math.round(Number(editingPrice.value) * 100);
    const quantity = Math.round(Number(editingQuantity.value));

    if (!Number.isFinite(cents) || !Number.isFinite(quantity)) {
        return 0;
    }

    return Math.max(0, cents) * Math.max(1, quantity);
});

function saveItem(order, item) {
    const cents = Math.round(Number(editingPrice.value) * 100);
    const quantity = Math.round(Number(editingQuantity.value));

    // Zero is a price (a comped dish) but not a quantity — a line nobody is
    // having is a removal, which has its own button and its own trail.
    //
    // Said out loud rather than returned from silently: a button that does
    // nothing at all is indistinguishable from one that saved and lost the
    // change, and the person pressing it is correcting money.
    if (!Number.isFinite(cents) || cents < 0 || !Number.isFinite(quantity) || quantity < 1) {
        window.alert(t('staff.edit_bill_invalid'));

        return;
    }

    router.patch(
        route('orders.items.update', [order.id, item.id]),
        { unit_price_cents: cents, quantity },
        {
            preserveScroll: true,
            // Without this the page component remounts on the redirect back
            // and expandedId resets, so a successful save closed the panel
            // the administrator was working in — which looks far more like a
            // failure than like the edit it actually was.
            preserveState: true,
            onSuccess: () => { editingItemId.value = null; },
            onError: (errors) => { window.alert(Object.values(errors).join('\n')); },
        }
    );
}

function removeItem(order, item) {
    if (!window.confirm(`${item.product_name} — ${t('common.are_you_sure')}`)) {
        return;
    }

    router.delete(route('orders.items.destroy', [order.id, item.id]), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { editingItemId.value = null; },
    });
}

// Sends the bill to the till's receipt printer. The same action the cashier
// screen offers, put here because this list is where a corrected bill is
// finished — and a bill corrected after the fact is exactly the one whose
// old paper is now wrong and has to be reprinted.
function printForCashier(order) {
    router.post(route('orders.print-cashier', order.id), {}, {
        preserveScroll: true,
        preserveState: true,
    });
}

function toggleExpanded(order) {
    expandedId.value = expandedId.value === order.id ? null : order.id;
}
</script>

<template>
    <Head :title="$t('nav.orders')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('nav.orders') }}</template>

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <input
                type="date"
                :value="date"
                class="focus-brand rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                @change="reload({ date: $event.target.value })"
            />

            <div class="flex gap-3 text-sm text-gray-500">
                <span>{{ t('staff.orders_count', { count: summary.total_orders }) }}</span>
                <span class="font-medium text-gray-900 dark:text-gray-100">{{ formatPrice(summary.paid_revenue_cents) }} {{ t('staff.collected') }}</span>
            </div>
        </div>

        <div class="mb-4 flex gap-2 overflow-x-auto pb-1">
            <button
                type="button"
                class="shrink-0 rounded-full border px-3.5 py-1.5 text-sm font-medium"
                :class="!status ? 'border-transparent text-white' : 'border-gray-200 text-gray-600 dark:border-gray-700 dark:text-gray-300'"
                :style="!status ? { backgroundColor: 'var(--brand-primary)' } : {}"
                @click="reload({ status: null })"
            >
                {{ t('menu.all_categories') }} ({{ Object.values(statusCounts).reduce((a, b) => a + b, 0) }})
            </button>
            <button
                v-for="(count, key) in statusCounts"
                :key="key"
                type="button"
                class="shrink-0 rounded-full border px-3.5 py-1.5 text-sm font-medium"
                :class="status === key ? 'border-transparent text-white' : 'border-gray-200 text-gray-600 dark:border-gray-700 dark:text-gray-300'"
                :style="status === key ? { backgroundColor: 'var(--brand-primary)' } : {}"
                @click="reload({ status: key })"
            >
                {{ t(`orders.statuses.${key}`) }} ({{ count }})
            </button>
        </div>

        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                <thead class="bg-gray-50 dark:bg-gray-900/60">
                    <tr class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-3 text-start">{{ t('common.time') }}</th>
                        <th class="px-4 py-3 text-start">{{ t('common.name') }}</th>
                        <th class="px-4 py-3 text-start">{{ t('tables.title') }}</th>
                        <th class="px-4 py-3 text-start">{{ t('common.status') }}</th>
                        <th class="px-4 py-3 text-start">{{ t('tables.paid') }}</th>
                        <th class="px-4 py-3 text-end">{{ t('common.price') }}</th>
                        <th class="px-4 py-3 text-end">{{ t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                    <tr v-if="orders.length === 0">
                        <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-400">{{ t('staff.no_orders') }}</td>
                    </tr>
                    <template v-for="order in orders" :key="order.id">
                        <tr
                            class="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/40"
                            @click="toggleExpanded(order)"
                        >
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-500">{{ formatTime(order.created_at) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">
                                <span class="inline-flex items-center gap-1.5">
                                    {{ order.reference }}
                                    <NavIcon
                                        v-if="hasNotes(order)"
                                        name="note"
                                        class="h-3.5 w-3.5 shrink-0"
                                        :class="expandedId === order.id ? 'text-gray-900 dark:text-gray-100' : 'text-amber-500'"
                                        :title="t('menu.notes')"
                                    />
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{{ order.table_label ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="statusBadgeClasses(order.status_color)">
                                    {{ t(`orders.statuses.${order.status}`) }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm">
                                <span :class="order.is_paid ? 'text-teal-600' : 'text-gray-400'">
                                    {{ order.is_paid ? t('tables.paid') : t('tables.unpaid') }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-end text-sm tabular-nums text-gray-900 dark:text-gray-100">
                                {{ formatPrice(order.total_cents) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-end text-sm" @click.stop>
                                <div class="flex items-center justify-end gap-3">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 text-gray-500 underline hover:text-gray-800 dark:hover:text-gray-200"
                                        @click="printForCashier(order)"
                                    >
                                        <NavIcon name="receipt" class="h-4 w-4" />
                                        {{ t('staff.print_cashier') }}
                                    </button>
                                    <a :href="route('orders.invoice', order.id)" target="_blank" rel="noopener" class="text-gray-500 underline hover:text-gray-800 dark:hover:text-gray-200">
                                        {{ t('staff.invoice') }}
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="expandedId === order.id" class="bg-gray-50 dark:bg-gray-800/40">
                            <td colspan="7" class="px-4 py-3">
                                <!-- This screen is the one place a bill can
                                     be corrected after it is over, so it is
                                     also the only place that can warn about
                                     what that creates: the recorded total
                                     moves, the collected amount does not. -->
                                <p
                                    v-if="isClosed(order)"
                                    class="mb-2 flex gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-[11px] leading-relaxed text-red-800 dark:border-red-900/60 dark:bg-red-900/20 dark:text-red-300"
                                >
                                    <NavIcon name="note" class="h-4 w-4 shrink-0" />
                                    <span>{{ t('staff.edit_bill_closed_warning') }}</span>
                                </p>

                                <ul class="space-y-1.5 text-sm text-gray-700 dark:text-gray-300">
                                    <li v-for="item in order.items" :key="item.id" class="flex flex-wrap items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <span class="font-medium">{{ item.quantity }}×</span> {{ item.product_name }}
                                            <span v-if="item.modifiers.length" class="text-gray-400">({{ item.modifiers.join(', ') }})</span>
                                            <div v-if="item.notes" class="ms-4 flex items-start gap-1 text-xs italic text-amber-600 dark:text-amber-500">
                                                <NavIcon name="note" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                                {{ item.notes }}
                                            </div>
                                        </div>

                                        <!-- Offered whatever state the bill is in. Only an
                                             administrator reaches this screen, and correcting a
                                             bill after the table has left is the reason it is
                                             here — see StaffOrderController. The warning above
                                             carries what that costs. -->
                                        <div class="flex shrink-0 items-center gap-2">
                                            <template v-if="editingItemId === item.id">
                                                <input
                                                    v-model.number="editingQuantity"
                                                    type="number"
                                                    step="1"
                                                    min="1"
                                                    max="999"
                                                    :aria-label="t('staff.quantity')"
                                                    class="w-16 rounded-md border-gray-300 py-1 text-sm tabular-nums dark:border-gray-700 dark:bg-gray-950"
                                                    @keyup.enter="saveItem(order, item)"
                                                    @keyup.esc="editingItemId = null"
                                                />
                                                <input
                                                    v-model.number="editingPrice"
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    :aria-label="t('staff.unit_price')"
                                                    class="w-24 rounded-md border-gray-300 py-1 text-sm tabular-nums dark:border-gray-700 dark:bg-gray-950"
                                                    @keyup.enter="saveItem(order, item)"
                                                    @keyup.esc="editingItemId = null"
                                                />
                                                <span class="w-20 text-end text-sm font-medium tabular-nums" :class="editingSubtotalCents === item.subtotal_cents ? 'text-gray-400' : 'text-gray-900 dark:text-gray-100'">
                                                    {{ formatPrice(editingSubtotalCents) }}
                                                </span>
                                                <button type="button" class="text-xs font-medium text-green-600" @click="saveItem(order, item)">
                                                    {{ $t('common.save') }}
                                                </button>
                                                <button type="button" class="text-xs text-gray-400" @click="editingItemId = null">
                                                    {{ $t('common.cancel') }}
                                                </button>
                                                <button type="button" class="text-xs font-medium text-red-600" @click="removeItem(order, item)">
                                                    {{ $t('common.delete') }}
                                                </button>
                                            </template>

                                            <template v-else>
                                                <span class="text-xs text-gray-400 tabular-nums">
                                                    {{ formatPrice(item.unit_price_cents) }} × {{ item.quantity }}
                                                </span>
                                                <span class="w-20 text-end text-sm font-medium tabular-nums">{{ formatPrice(item.subtotal_cents) }}</span>
                                                <button
                                                    type="button"
                                                    class="text-xs font-medium"
                                                    style="color: var(--brand-primary)"
                                                    @click="startEditingItem(item)"
                                                >
                                                    {{ $t('common.edit') }}
                                                </button>
                                            </template>
                                        </div>
                                    </li>
                                </ul>
                                <!-- The two numbers the warning above is about,
                                     side by side, so a correction that leaves
                                     them apart is visible rather than inferred. -->
                                <div
                                    v-if="order.is_paid && order.amount_paid_cents !== null && order.amount_paid_cents !== order.total_cents"
                                    class="mt-2 flex justify-end gap-4 border-t border-gray-200 pt-2 text-xs tabular-nums text-gray-500 dark:border-gray-700"
                                >
                                    <span>{{ t('staff.total') }}: {{ formatPrice(order.total_cents) }}</span>
                                    <span>{{ t('staff.collected') }}: {{ formatPrice(order.amount_paid_cents) }}</span>
                                </div>

                                <div v-if="order.notes" class="mt-2 flex items-start gap-1.5 border-t border-gray-200 pt-2 text-sm italic text-amber-700 dark:border-gray-700 dark:text-amber-500">
                                    <NavIcon name="note" class="mt-0.5 h-4 w-4 shrink-0" />
                                    {{ order.notes }}
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </AuthenticatedLayout>
</template>
