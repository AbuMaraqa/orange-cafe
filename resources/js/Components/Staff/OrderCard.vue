<script setup>
import { computed, ref } from 'vue';
import NavIcon from '@/Components/NavIcon.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useOrderStatus } from '@/composables/useOrderStatus';
import { PAYMENT_CARD, PAYMENT_CASH, PAYMENT_METHODS, PAYMENT_METHOD_ICONS } from '@/constants/paymentMethods';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    order: {
        type: Object,
        required: true,
    },
    nextStatusLabel: {
        type: String,
        default: null,
    },
    showPay: {
        type: Boolean,
        default: false,
    },
    showInvoice: {
        type: Boolean,
        default: false,
    },
    showPrint: {
        type: Boolean,
        default: false,
    },
    showCashierPrint: {
        type: Boolean,
        default: false,
    },
    showConfirm: {
        type: Boolean,
        default: false,
    },
    removableItems: {
        type: Boolean,
        default: false,
    },
    showItemPrices: {
        type: Boolean,
        default: false,
    },
    // The cashier juggles many tables at once and mostly just needs to see
    // who owes what — the full item list is secondary detail, so it starts
    // collapsed behind a tap instead of crowding every card open by
    // default. Waiter/Kitchen/Dashboard need the items visible at a glance
    // to actually do their job, so they leave this off.
    collapsible: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['advance', 'pay', 'remove-item', 'print', 'print-cashier', 'confirm']);
const { t } = useI18n();
const page = usePage();
const { statusBadgeClasses } = useOrderStatus();
const expanded = ref(!props.collapsible);

function formatPrice(cents) {
    return `${page.props.branding.currency_symbol}${(cents / 100).toFixed(2)}`;
}

function timeAgo(iso) {
    const minutes = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000));
    return minutes < 1 ? t('staff.just_now') : t('staff.minutes_ago', { minutes });
}

// Cash collected doesn't always match the bill exactly — a cashier may
// round down or discount at the till, so "mark paid" asks for the actual
// amount rather than assuming it equals the total.
const showingPayForm = ref(false);
const payAmount = ref('');

// Cash unless the cashier says otherwise, reset every time the box opens —
// see TableBillModal for why this must not remember the last choice.
const payMethod = ref(PAYMENT_CASH);
const payReference = ref('');

const payingByCard = computed(() => payMethod.value === PAYMENT_CARD);

function choosePayMethod(method) {
    payMethod.value = method;
    if (method !== PAYMENT_CARD) payReference.value = '';
}

function openPayForm() {
    payAmount.value = (props.order.total_cents / 100).toFixed(2);
    choosePayMethod(PAYMENT_CASH);
    showingPayForm.value = true;
}

const payDifferenceCents = computed(() => {
    const entered = Math.round(Number(payAmount.value) * 100);
    return Number.isFinite(entered) ? entered - props.order.total_cents : 0;
});

function confirmPay() {
    const cents = Math.round(Number(payAmount.value) * 100);
    if (!Number.isFinite(cents) || cents < 0) return;

    // Optional: a blank box is a cafe that does not reconcile against its
    // terminal, not a mistake worth stopping a cashier over with a customer
    // in front of them. Null rather than the empty string, so the column
    // holds either a number somebody can look up or nothing at all.
    const reference = payReference.value.trim();

    // An object rather than the bare amount this used to emit: the till now
    // has three things to say about a payment, and a positional second
    // argument is the kind of thing a later caller passes in the wrong order
    // without the code looking wrong anywhere.
    emit('pay', {
        amount_paid_cents: cents,
        payment_method: payMethod.value,
        payment_reference: payingByCard.value && reference !== '' ? reference : null,
    });

    showingPayForm.value = false;
}
</script>

<template>
    <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
        <div class="flex items-start justify-between">
            <div>
                <p class="font-semibold text-gray-900 dark:text-gray-100">
                    {{ order.table_label ? `${t('tables.title')} ${order.table_label}` : order.reference }}
                </p>
                <p class="text-xs text-gray-500">
                    <template v-if="order.table_label">{{ order.reference }} · </template>
                    <template v-if="order.customer_name">{{ order.customer_name }} · </template>
                    {{ timeAgo(order.created_at) }}
                </p>
            </div>
            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="statusBadgeClasses(order.status_color)">
                {{ t(`orders.statuses.${order.status}`) }}
            </span>
        </div>

        <button
            v-if="collapsible && !expanded"
            type="button"
            class="mt-3 flex w-full items-center justify-between rounded-md bg-gray-50 px-2.5 py-1.5 text-xs text-gray-500 hover:bg-gray-100 dark:bg-gray-800/60 dark:text-gray-400 dark:hover:bg-gray-800"
            @click="expanded = true"
        >
            <span>{{ t('staff.items_count', { count: order.items.length }) }}</span>
            <span class="font-medium">{{ t('staff.show_details') }} ▾</span>
        </button>

        <template v-else>
            <ul class="mt-3 space-y-1.5 text-sm text-gray-700 dark:text-gray-300">
                <li v-for="item in order.items" :key="item.id" class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <span class="font-medium">{{ item.quantity }}×</span> {{ item.product_name }}
                        <span v-if="item.modifiers.length" class="text-gray-400">({{ item.modifiers.join(', ') }})</span>
                        <div v-if="item.notes" class="ms-4 text-xs italic text-gray-400">{{ item.notes }}</div>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <span v-if="showItemPrices" class="tabular-nums text-gray-500 dark:text-gray-400">{{ formatPrice(item.subtotal_cents) }}</span>
                        <button
                            v-if="removableItems && order.status === 'pending'"
                            type="button"
                            class="text-gray-300 hover:text-red-600"
                            :title="t('common.delete')"
                            @click="emit('remove-item', item)"
                        ><NavIcon name="close" class="h-3.5 w-3.5" /></button>
                    </div>
                </li>
            </ul>
            <p v-if="order.notes" class="mt-2 text-xs italic text-gray-500">{{ order.notes }}</p>
            <button
                v-if="collapsible"
                type="button"
                class="mt-2 text-xs font-medium text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                @click="expanded = false"
            >
                {{ t('staff.hide_details') }} ▴
            </button>
        </template>

        <div class="mt-3 border-t border-gray-100 pt-3 dark:border-gray-800">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <span class="font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ formatPrice(order.total_cents) }}</span>
                    <div
                        v-if="order.is_paid && order.amount_paid_cents !== null && order.amount_paid_cents !== order.total_cents"
                        class="text-xs"
                        :class="order.amount_paid_cents < order.total_cents ? 'text-red-500' : 'text-teal-600'"
                    >
                        {{ t('admin.accounting.received') }} {{ formatPrice(order.amount_paid_cents) }}
                    </div>
                    <!-- How it was settled. See TableBillModal: the question
                         asked about a closed bill is nearly always this one. -->
                    <div
                        v-if="order.payment_method"
                        class="mt-0.5 flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400"
                    >
                        <NavIcon :name="PAYMENT_METHOD_ICONS[order.payment_method]" class="h-3.5 w-3.5" />
                        <span>{{ t(`staff.payment_methods.${order.payment_method}`) }}</span>
                        <span v-if="order.payment_reference" class="tabular-nums" dir="ltr">· {{ order.payment_reference }}</span>
                    </div>
                </div>
                <div class="flex flex-wrap justify-end gap-2">
                    <a v-if="showInvoice" :href="route('orders.invoice', order.id)" target="_blank" rel="noopener">
                        <SecondaryButton type="button" class="!px-2.5">
                            <NavIcon name="printer" class="h-4 w-4" />
                        </SecondaryButton>
                    </a>
                    <SecondaryButton v-if="showPrint" type="button" @click="emit('print')">
                        {{ t('staff.print') }}
                    </SecondaryButton>
                    <SecondaryButton v-if="showCashierPrint" type="button" @click="emit('print-cashier')">
                        {{ t('staff.print_cashier') }}
                    </SecondaryButton>
                    <SecondaryButton v-if="showPay && !order.is_paid && !showingPayForm" type="button" @click="openPayForm">
                        {{ t('staff.mark_paid') }}
                    </SecondaryButton>
                    <span v-else-if="showPay && order.is_paid" class="self-center text-xs font-medium text-teal-600">
                        {{ t('tables.paid') }}
                    </span>
                    <PrimaryButton v-if="showConfirm && order.status === 'pending'" type="button" @click="emit('confirm')">
                        {{ t('staff.confirm_order') }}
                    </PrimaryButton>
                    <PrimaryButton v-if="nextStatusLabel" type="button" @click="emit('advance')">
                        {{ nextStatusLabel }}
                    </PrimaryButton>
                </div>
            </div>

            <div v-if="showingPayForm" class="mt-3 rounded-md bg-gray-50 p-3 dark:bg-gray-800/60">
                <label class="text-xs font-medium text-gray-600 dark:text-gray-400">{{ t('staff.amount_received') }}</label>
                <div class="mt-1 flex items-center gap-2">
                    <input
                        v-model="payAmount"
                        type="number"
                        step="0.01"
                        min="0"
                        dir="ltr"
                        class="block w-28 rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-500 focus:ring-gray-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                        @keyup.enter="confirmPay"
                    />
                    <span
                        v-if="payDifferenceCents !== 0"
                        class="text-xs font-medium"
                        :class="payDifferenceCents < 0 ? 'text-red-500' : 'text-teal-600'"
                    >
                        {{ payDifferenceCents < 0 ? t('staff.discount') : t('staff.change_due') }}: {{ formatPrice(Math.abs(payDifferenceCents)) }}
                    </span>
                </div>

                <!-- Cash or card, then the terminal's reference when it is a
                     card — offered, not demanded. Same two-button shape as the
                     table bill sheet, so a cashier moving between the floor
                     plan and this list is not learning the control twice. -->
                <div class="mt-3 grid grid-cols-2 gap-2">
                    <button
                        v-for="method in PAYMENT_METHODS"
                        :key="method"
                        type="button"
                        class="flex h-9 items-center justify-center gap-1.5 rounded-md border text-xs font-medium transition-colors"
                        :class="payMethod === method
                            ? 'border-gray-900 bg-gray-900 text-white dark:border-gray-100 dark:bg-gray-100 dark:text-gray-900'
                            : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300'"
                        @click="choosePayMethod(method)"
                    >
                        <NavIcon :name="PAYMENT_METHOD_ICONS[method]" class="h-4 w-4" />
                        {{ t(`staff.payment_methods.${method}`) }}
                    </button>
                </div>

                <div v-if="payingByCard" class="mt-2">
                    <input
                        v-model="payReference"
                        type="text"
                        dir="ltr"
                        inputmode="numeric"
                        autocomplete="off"
                        maxlength="60"
                        :placeholder="t('staff.payment_reference_placeholder')"
                        class="block w-full rounded-md border-gray-300 text-sm tabular-nums shadow-sm focus:border-gray-500 focus:ring-gray-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                        @keyup.enter="confirmPay"
                    />
                </div>

                <div class="mt-2 flex justify-end gap-2">
                    <SecondaryButton type="button" @click="showingPayForm = false">{{ t('common.cancel') }}</SecondaryButton>
                    <PrimaryButton type="button" @click="confirmPay">{{ t('staff.confirm_payment') }}</PrimaryButton>
                </div>
            </div>
        </div>
    </div>
</template>
