<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import NavIcon from '@/Components/NavIcon.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useOrderStatus } from '@/composables/useOrderStatus';
import { ACTIVE_STATUSES, nextStatus } from '@/constants/orderStatus';
import { router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

/**
 * The bill for one table, opened from the floor plan.
 *
 * This is the screen a cashier stands in front of with a customer waiting,
 * so it is laid out as a bill rather than as a card with buttons: who the
 * table is, what is on it, what it costs, and then one obvious next action
 * with everything else stepped down behind it. The previous version gave
 * "print", "print for cashier", "mark paid" and the status control the same
 * visual weight, which makes the one you actually want the one you have to
 * read for.
 *
 * A live ticket now has two words for its progress rather than four — the
 * kitchen has it, or it is ready to carry. See config/orders.php.
 *
 * An administrator gets an edit mode on top of it — quantity and price per
 * line, straight on the bill, saving as you go. Repricing a line used to
 * mean leaving the floor plan for the orders list, and a table asking for a
 * discount while standing at the till cannot wait for that.
 *
 * Line edits are issued from here rather than emitted, because unlike
 * pay/print/advance they have one canonical endpoint and no per-page
 * behaviour: every screen that shows a bill edits it the same way.
 */
const props = defineProps({
    table: { type: Object, required: true },
    // Admin. Editing is refused server-side too, and once the order is paid.
    canEdit: { type: Boolean, default: false },
    canPay: { type: Boolean, default: false },
    canConfirm: { type: Boolean, default: false },
    canAdvance: { type: Boolean, default: false },
    canMove: { type: Boolean, default: false },
    canPrint: { type: Boolean, default: false },
    canPrintCashier: { type: Boolean, default: false },
    canInvoice: { type: Boolean, default: false },
    canCancel: { type: Boolean, default: false },
    // The till's price list of things nobody cooks, and whether this screen
    // may put one on a bill. Both off by default: the floor does not decide
    // what a table is charged, and the route says so too.
    serviceCharges: { type: Array, default: () => [] },
    canAddServiceCharge: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'move']);

const { t } = useI18n();
const page = usePage();
const { statusBadgeClasses } = useOrderStatus();

const order = computed(() => props.table.active_order ?? null);

/**
 * Rounds this table has asked for that nobody has accepted yet.
 *
 * A customer ordering again after their first round was approved cannot add
 * to it unreviewed, so the round arrives as its own order and waits. It is
 * listed under the bill so the table's whole ask is readable in one place.
 */
const pendingRounds = computed(() => props.table.pending_rounds ?? []);

function confirmRound(round) {
    router.post(route('orders.confirm', round.id), {}, { preserveScroll: true });
}
const currency = computed(() => page.props.branding.currency_symbol);

function formatPrice(cents) {
    return `${currency.value}${(cents / 100).toFixed(2)}`;
}

/**
 * Cancelling clears the table without taking any money for it.
 *
 * Confirmed first, and the wording names the table, because this is the
 * one button on the sheet that destroys work: the alternative people
 * reached for before it existed was marking the table paid, which put
 * money into the day's takings that nobody handed over.
 */
function confirmCancel() {
    if (!confirm(t('orders.cancel_confirm', { table: props.table.label }))) {
        return;
    }

    router.patch(route('orders.cancel', order.value.id), {}, {
        preserveScroll: true,
        // The table is empty now, so the sheet describing its order is
        // describing something that no longer exists.
        onSuccess: () => emit('close'),
    });
}

// One shape repeated for the secondary row, so the eye groups them as "the
// other things" instead of reading each one as a decision of its own.
const CHIP =
    'inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs font-medium ' +
    'text-gray-600 transition-colors hover:border-gray-300 hover:bg-gray-50 hover:text-gray-900 ' +
    'dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-gray-100';

const CHIP_ACTIVE =
    'inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 text-xs font-medium transition-colors ' +
    'border-amber-300 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-300';

// Recomputed on a tick so an open bill visibly ages — how long a table has
// been sitting is half of what a cashier is judging when they open it.
const nowTick = ref(Date.now());
let tickInterval = null;

const elapsed = computed(() => {
    void nowTick.value;
    if (!order.value) return '';

    const minutes = Math.max(0, Math.round((Date.now() - new Date(order.value.created_at).getTime()) / 60000));

    return minutes < 1 ? t('staff.just_now') : t('staff.minutes_ago', { minutes });
});

function onKeydown(event) {
    if (event.key === 'Escape') emit('close');
}

onMounted(() => {
    tickInterval = setInterval(() => (nowTick.value = Date.now()), 30000);
    document.addEventListener('keydown', onKeydown);
});

onUnmounted(() => {
    clearInterval(tickInterval);
    clearTimeout(priceTimer);
    document.removeEventListener('keydown', onKeydown);
});

/* ------------------------------------------------------------------ *
 * Editing the bill
 * ------------------------------------------------------------------ */

const editing = ref(false);
// Only for an administrator, whatever screen passes canEdit in. The edit
// routes are admin-only server-side, so offering the controls to the floor
// can only end as a 403 in front of a customer — the floor confirms an
// order, it does not reprice it. See WaiterConfirmsButDoesNotEditTest.
const isAdmin = computed(() => (page.props.auth?.roles ?? []).includes('admin'));

/**
 * Whether the lines on this bill may still be changed.
 *
 * While the table is still running: not paid, and not a completed or
 * cancelled order, which is a record rather than a bill. The same rule
 * StaffOrderController enforces — the controls must not be offered where
 * they would answer 403, because the person pressing one believes the edit
 * is possible and only learns otherwise in front of a customer.
 *
 * Confirmation used to be the cutoff and no longer is: the corrections an
 * owner actually deals with turn up after the kitchen has the ticket. What
 * that costs is that the paper at the pass no longer has to agree with the
 * bill, so an edit past confirmation says so on screen (kitchenHasTicket)
 * and is logged server-side. Nothing else notices the disagreement.
 */
const editable = computed(() =>
    props.canEdit
    && isAdmin.value
    && !!order.value
    && !order.value.is_paid
    && ACTIVE_STATUSES.includes(order.value.status)
);

// Editing from here on rewrites a bill whose ticket is already being cooked
// from. Worth one line of warning, in the one place the edit happens.
const kitchenHasTicket = computed(() => editing.value && order.value?.status !== 'pending');

// A line the cashier is typing into right now. Its draft has to survive the
// prop refresh that every save triggers, or the field would fight back
// mid-keystroke.
const focusedItemId = ref(null);

// Per-line working copy. Quantity is a number; the price is held as the
// string being typed, in currency units — cents are an internal detail and
// nobody reads a bill in them.
const drafts = reactive({});

function syncDrafts() {
    const items = order.value?.items ?? [];
    const present = new Set();

    items.forEach((item) => {
        present.add(item.id);
        if (drafts[item.id] && focusedItemId.value === item.id) return;

        drafts[item.id] = {
            quantity: item.quantity,
            price: (item.unit_price_cents / 100).toFixed(2),
        };
    });

    Object.keys(drafts).forEach((id) => {
        if (!present.has(Number(id))) delete drafts[id];
    });
}

watch(() => order.value?.items, syncDrafts, { immediate: true, deep: true });

// A line's own figure, following the draft while it is being edited so the
// effect of a discount shows before it is committed.
function lineTotalCents(item) {
    const draft = drafts[item.id];
    if (!editing.value || !draft) return item.subtotal_cents;

    const cents = Math.round(Number(draft.price) * 100);
    const unit = Number.isFinite(cents) && cents >= 0 ? cents : item.unit_price_cents;

    return unit * (draft.quantity || item.quantity);
}

const displayedTotalCents = computed(() => {
    if (!order.value) return 0;
    if (!editing.value) return order.value.total_cents;

    // The stored total is already net of any discount, so a live preview
    // built from the lines has to take the same amount off — otherwise
    // editing a line would appear to undo the discount.
    const gross = order.value.items.reduce((sum, item) => sum + lineTotalCents(item), 0);

    return Math.max(0, gross - (order.value.discount_cents ?? 0));
});

/**
 * Edits in flight, keyed by line id.
 *
 * Nothing is sent while a cashier is still adjusting. Tapping "+" five times
 * used to be five PATCH requests, five round trips and five re-renders — on
 * a tablet over café wifi they arrive out of order, so the quantity visibly
 * jumps 5, 3, 4 as the replies land, and the line ends up at whichever
 * request answered last rather than at what the cashier chose.
 *
 * Keyed per line, not one shared timer. A single timer meant that starting
 * to edit a second line cancelled the first line's pending save, and the
 * price the cashier had just typed was silently discarded.
 *
 * Payloads merge, so changing a quantity and a price on the same line while
 * it settles is one request carrying both.
 *
 * @type {Map<number, {timer: number, payload: object}>}
 */
const pendingEdits = new Map();

// Long enough to cover a run of taps on a stepper, short enough that
// letting go feels like it saved.
const SETTLE_MS = 600;

function queueEdit(item, payload) {
    const entry = pendingEdits.get(item.id) ?? { timer: null, payload: {} };

    clearTimeout(entry.timer);
    entry.payload = { ...entry.payload, ...payload };
    entry.timer = setTimeout(() => flushEdit(item.id), SETTLE_MS);

    pendingEdits.set(item.id, entry);
}

/**
 * preserveState keeps this popup open across the round trip — without it the
 * page component remounts and the bill closes under the cashier's hand.
 */
function flushEdit(itemId) {
    const entry = pendingEdits.get(itemId);

    if (!entry || !order.value) return;

    clearTimeout(entry.timer);
    pendingEdits.delete(itemId);

    router.patch(route('orders.items.update', [order.value.id, itemId]), entry.payload, {
        preserveScroll: true,
        preserveState: true,
    });
}

/** Everything still waiting, sent now. */
function flushEdits() {
    [...pendingEdits.keys()].forEach(flushEdit);
}

function changeQuantity(item, delta) {
    const current = drafts[item.id]?.quantity ?? item.quantity;
    const next = Math.min(999, Math.max(1, current + delta));
    if (next === current) return;

    // The draft moves at once, so the number under the finger is always the
    // one that was tapped; only the request waits.
    drafts[item.id].quantity = next;

    queueEdit(item, { quantity: next });
}

function onPriceInput(item) {
    focusedItemId.value = item.id;

    const cents = Math.round(Number(drafts[item.id]?.price) * 100);

    // Mid-typing values are not sent: "12.50" passes through 1 and 12 on the
    // way, and each would be a price somebody was charged if it landed.
    if (!Number.isFinite(cents) || cents < 0) return;

    queueEdit(item, { unit_price_cents: cents });
}

function onPriceBlur(item) {
    flushEdit(item.id);
    focusedItemId.value = null;
}

// Leaving edit mode, or closing the sheet, must not lose the last change —
// the cashier has finished, which is exactly when the settle timer is still
// counting down.
watch(editing, (isEditing) => {
    if (!isEditing) flushEdits();
});

// Confirming while the sheet is open takes the lines out of reach, and edit
// mode has to follow — otherwise the controls stay on screen answering 403,
// which reads as the app being broken rather than as the rule it is.
watch(editable, (stillEditable) => {
    if (!stillEditable) editing.value = false;
});

onUnmounted(flushEdits);

function removeLine(item) {
    if (!window.confirm(`${item.product_name} — ${t('common.are_you_sure')}`)) return;

    router.delete(route('orders.items.destroy', [order.value.id, item.id]), {
        preserveScroll: true,
        preserveState: true,
    });
}

/* ------------------------------------------------------------------ *
 * Actions
 * ------------------------------------------------------------------ */

const advanceLabel = computed(() => {
    if (!props.canAdvance || !order.value) return null;

    const next = nextStatus(order.value.status);

    return next ? t(`orders.statuses.${next}`) : null;
});

function post(routeName) {
    router.post(route(routeName, order.value.id), {}, { preserveScroll: true, preserveState: true });
}

function advance() {
    const next = nextStatus(order.value.status);
    if (!next) return;

    router.patch(route('orders.status', order.value.id), { status: next }, {
        preserveScroll: true,
        preserveState: true,
    });
}

/* ------------------------------------------------------------------ *
 * Discount on the whole bill
 * ------------------------------------------------------------------ */

// Kept apart from repricing individual lines on purpose. Knocking money off
// item by item makes the record lie — it would say the coffee costs 8 when
// the menu says 12 — and at the end of the day nobody can tell a discount
// from a price change. This is one number against the bill.
const showingDiscountForm = ref(false);
const discountAmount = ref('');
const discountReason = ref('');

/* ------------------------------------------------------------------ *
 * Service charges
 * ------------------------------------------------------------------ */

/**
 * Charging a table for something nobody cooks — a seat held past two
 * hours, corkage.
 *
 * A line rather than a negative discount, because the customer is being
 * asked to pay for it and has to be able to read what for. The price list
 * is the admin's (Admin/ServiceCharges); the till presses one, and can then
 * reprice the line like any other if the night calls for it.
 */
const showingServiceCharges = ref(false);

const serviceChargeOptions = computed(() => props.serviceCharges ?? []);

function openServiceCharges() {
    editing.value = false;
    showingPayForm.value = false;
    showingDiscountForm.value = false;
    showingServiceCharges.value = true;
}

function addServiceCharge(charge) {
    router.post(route('orders.service-charges.store', order.value.id), {
        service_charge_id: charge.id,
    }, {
        preserveScroll: true,
        onSuccess: () => { showingServiceCharges.value = false; },
    });
}

// What the lines come to before anything is taken off. The stored total is
// already net, so the discount has to be added back to show it.
const subtotalCents = computed(() =>
    order.value ? order.value.total_cents + (order.value.discount_cents ?? 0) : 0
);

function openDiscountForm() {
    editing.value = false;
    showingPayForm.value = false;
    discountAmount.value = order.value.discount_cents ? (order.value.discount_cents / 100).toFixed(2) : '';
    discountReason.value = order.value.discount_reason ?? '';
    showingDiscountForm.value = true;
}

// A discount is agreed out loud as a percentage far more often than as an
// amount, so the buttons do the arithmetic rather than the cashier.
function setDiscountPercent(percent) {
    discountAmount.value = (Math.round(subtotalCents.value * percent / 100) / 100).toFixed(2);
}

function saveDiscount(cents) {
    router.patch(route('orders.discount', order.value.id), {
        discount_cents: cents,
        discount_reason: cents > 0 ? (discountReason.value || null) : null,
    }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { showingDiscountForm.value = false; },
    });
}

function confirmDiscount() {
    const cents = Math.round(Number(discountAmount.value) * 100);
    if (!Number.isFinite(cents) || cents < 0 || cents > subtotalCents.value) return;

    saveDiscount(cents);
}

// Cash collected doesn't always match the bill exactly — a cashier may
// round down or discount at the till, so this asks for the amount actually
// taken rather than assuming it equals the total.
const showingPayForm = ref(false);
const payAmount = ref('');

/**
 * What is still owed — the total less anything already handed over.
 *
 * This, not the total, is what the pay box should open on: a bill that has
 * been part-paid must never invite the cashier to take the whole amount
 * again.
 */
const outstandingCents = computed(() => order.value?.outstanding_cents ?? order.value?.total_cents ?? 0);

function openPayForm() {
    editing.value = false;
    showingDiscountForm.value = false;
    payAmount.value = (outstandingCents.value / 100).toFixed(2);
    showingPayForm.value = true;
}

const payDifferenceCents = computed(() => {
    if (!order.value) return 0;

    const entered = Math.round(Number(payAmount.value) * 100);

    return Number.isFinite(entered) ? entered - outstandingCents.value : 0;
});

function pay(partial) {
    const cents = Math.round(Number(payAmount.value) * 100);
    if (!Number.isFinite(cents) || cents < 0) return;

    router.patch(route('orders.pay', order.value.id), { amount_paid_cents: cents, partial }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { showingPayForm.value = false; },
    });
}

/**
 * Settle the bill: the amount entered closes it whatever it is, because a
 * cashier rounding 202 down to 200 has finished with the table.
 */
function confirmPay() {
    pay(false);
}

/**
 * Take this much and leave the rest owing.
 *
 * The state that had nowhere to live before: a table paying half now had to
 * be either closed — putting money in the day's takings nobody handed over
 * — or left alone, losing the record of what was actually collected.
 *
 * Offered only when the amount does not already cover the bill; taking
 * "part" of the whole thing is just paying.
 */
function payPartially() {
    pay(true);
}

const canPayPartially = computed(() => {
    const cents = Math.round(Number(payAmount.value) * 100);

    return Number.isFinite(cents) && cents > 0 && cents < outstandingCents.value;
});

const paidBadgeClasses = computed(() =>
    order.value?.is_paid
        ? 'bg-teal-100 text-teal-700 dark:bg-teal-900/40 dark:text-teal-300'
        : 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'
);

const tileClasses = computed(() => {
    if (!order.value) {
        return 'border-gray-300 bg-white text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400';
    }

    return order.value.is_paid
        ? 'border-teal-500 bg-teal-50 text-teal-700 dark:bg-teal-900/30 dark:text-teal-300'
        : 'border-amber-500 bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300';
});
</script>

<template>
    <!-- A bottom sheet on a phone and a centred card from tablet up: a thumb
         reaches the bottom of a phone screen and not its middle, and these
         screens get used one-handed while walking. -->
    <div
        class="fixed inset-0 z-50 flex items-end justify-center bg-gray-900/60 backdrop-blur-sm sm:items-center sm:p-4"
        @click.self="emit('close')"
    >
        <div class="flex max-h-[92vh] w-full flex-col overflow-hidden rounded-t-2xl bg-white shadow-2xl sm:max-h-[88vh] sm:max-w-md sm:rounded-2xl dark:bg-gray-900">
            <!-- Header ------------------------------------------------- -->
            <div class="flex shrink-0 items-start gap-3 border-b border-gray-100 bg-gray-50/80 p-4 dark:border-gray-800 dark:bg-gray-950/50">
                <!-- Drawn like the floor-plan tile it was tapped on, so it
                     is obvious which table this bill belongs to. -->
                <div
                    class="flex h-12 w-12 shrink-0 items-center justify-center border-2 text-lg font-bold"
                    :class="[tileClasses, table.shape === 'round' ? 'rounded-full' : 'rounded-lg']"
                >
                    {{ table.label }}
                </div>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                            {{ t('tables.title') }} {{ table.label }}
                        </h3>
                        <span v-if="order" class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium" :class="statusBadgeClasses(order.status_color)">
                            {{ t(`orders.statuses.${order.status}`) }}
                        </span>
                        <span v-if="order" class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium" :class="paidBadgeClasses">
                            {{ order.is_paid
                                ? t('tables.paid')
                                : (order.is_partially_paid
                                    ? t('tables.partially_paid', { remaining: formatPrice(order.outstanding_cents) })
                                    : t('tables.unpaid')) }}
                        </span>
                    </div>

                    <p v-if="order" class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-gray-500 dark:text-gray-400">
                        <span class="tabular-nums" dir="ltr">{{ order.reference }}</span>
                        <span class="inline-flex items-center gap-1"><NavIcon name="clock" class="h-3 w-3" />{{ elapsed }}</span>
                        <span v-if="order.customer_name" class="font-medium text-gray-700 dark:text-gray-300">{{ order.customer_name }}</span>
                        <span v-if="order.guest_count" class="inline-flex items-center gap-1"><NavIcon name="users" class="h-3 w-3" />{{ order.guest_count }}</span>
                    </p>
                    <p v-else class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ t('staff.seats_count', { count: table.seats }) }}
                    </p>
                </div>

                <button
                    type="button"
                    class="-me-1 -mt-1 rounded-full p-2 text-gray-400 transition-colors hover:bg-gray-200 hover:text-gray-700 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                    :aria-label="t('common.close')"
                    @click="emit('close')"
                >
                    <NavIcon name="close" class="h-5 w-5" />
                </button>
            </div>

            <!-- Bill --------------------------------------------------- -->
            <div v-if="order" class="min-h-0 flex-1 overflow-y-auto px-4 py-3">
                <div
                    v-if="order.notes"
                    class="mb-3 flex gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900/60 dark:bg-amber-900/20 dark:text-amber-300"
                >
                    <NavIcon name="note" class="h-4 w-4 shrink-0" />
                    <span>{{ order.notes }}</span>
                </div>

                <p v-if="order.items.length === 0" class="py-6 text-center text-sm text-gray-400">
                    {{ t('staff.no_items') }}
                </p>

                <!-- Reading mode: a bill. Count on the outside edge, name in
                     the middle, money on the inside edge on tabular figures,
                     so the money column scans as a column. -->
                <ul v-else-if="!editing" class="divide-y divide-gray-100 dark:divide-gray-800">
                    <li v-for="item in order.items" :key="item.id" class="flex items-start gap-3 py-2.5">
                        <span class="mt-0.5 inline-flex h-6 min-w-[1.5rem] shrink-0 items-center justify-center rounded-md bg-gray-100 px-1.5 text-xs font-bold tabular-nums text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                            {{ item.quantity }}
                        </span>

                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium leading-snug text-gray-900 dark:text-gray-100">{{ item.product_name }}</p>
                            <p v-if="item.modifiers.length" class="text-xs text-gray-500 dark:text-gray-400">{{ item.modifiers.join('، ') }}</p>
                            <p v-if="item.notes" class="mt-0.5 flex items-start gap-1 text-xs text-amber-600 dark:text-amber-400">
                                <NavIcon name="note" class="mt-px h-3 w-3 shrink-0" /><span>{{ item.notes }}</span>
                            </p>
                        </div>

                        <div class="shrink-0 text-end">
                            <p class="text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ formatPrice(item.subtotal_cents) }}</p>
                            <p v-if="item.quantity > 1" class="text-[11px] tabular-nums text-gray-400">
                                {{ formatPrice(item.unit_price_cents) }} {{ t('staff.each') }}
                            </p>
                        </div>
                    </li>
                </ul>

                <!-- Rounds the table has asked for and nobody has accepted
                     yet, listed under the bill rather than on a screen of
                     their own.

                     A guest asking "what did we order?" means everything —
                     the food on its way and the round they asked for two
                     minutes ago. Answering from the bill alone tells a table
                     that ordered twice that it ordered once. Kept visually
                     apart, and priced apart, because it is not on the bill
                     until somebody accepts it. -->
                <section v-if="!editing && pendingRounds.length" class="mt-4">
                    <div class="mb-2 flex items-center gap-2">
                        <NavIcon name="clock" class="h-4 w-4 text-amber-500" />
                        <h4 class="text-xs font-semibold uppercase tracking-wide text-amber-600 dark:text-amber-400">
                            {{ t('staff.awaiting_confirmation') }}
                        </h4>
                    </div>

                    <div
                        v-for="round in pendingRounds"
                        :key="round.id"
                        class="mb-2 rounded-lg border border-amber-200 bg-amber-50/60 dark:border-amber-900/60 dark:bg-amber-900/10"
                    >
                        <ul class="divide-y divide-amber-100 px-3 dark:divide-amber-900/40">
                            <li v-for="item in round.items" :key="item.id" class="flex items-start gap-3 py-2.5">
                                <span class="mt-0.5 inline-flex h-6 min-w-[1.5rem] shrink-0 items-center justify-center rounded-md bg-amber-100 px-1.5 text-xs font-bold tabular-nums text-amber-800 dark:bg-amber-900/50 dark:text-amber-200">
                                    {{ item.quantity }}
                                </span>

                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium leading-snug text-gray-900 dark:text-gray-100">{{ item.product_name }}</p>
                                    <p v-if="item.modifiers.length" class="text-xs text-gray-500 dark:text-gray-400">{{ item.modifiers.join('، ') }}</p>
                                    <p v-if="item.notes" class="mt-0.5 flex items-start gap-1 text-xs text-amber-700 dark:text-amber-300">
                                        <NavIcon name="note" class="mt-px h-3 w-3 shrink-0" /><span>{{ item.notes }}</span>
                                    </p>
                                </div>

                                <div class="shrink-0 text-end">
                                    <p class="text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ formatPrice(item.subtotal_cents) }}</p>
                                    <p v-if="item.quantity > 1" class="text-[11px] tabular-nums text-gray-400">
                                        {{ formatPrice(item.unit_price_cents) }} {{ t('staff.each') }}
                                    </p>
                                </div>
                            </li>
                        </ul>

                        <div v-if="canConfirm" class="border-t border-amber-200 px-3 py-2 dark:border-amber-900/40">
                            <PrimaryButton
                                class="h-10 w-full justify-center text-sm"
                                type="button"
                                @click="confirmRound(round)"
                            >
                                <NavIcon name="check" class="me-1.5 h-4 w-4" />{{ t('staff.confirm_order') }}
                            </PrimaryButton>
                        </div>
                    </div>

                    <!-- What the table has asked for in total. The number a
                         guest is really asking about, and the one the bill
                         above deliberately does not show yet. -->
                    <div class="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-gray-800/60">
                        <span class="text-gray-600 dark:text-gray-400">{{ t('staff.requested_total') }}</span>
                        <span class="font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                            {{ formatPrice(table.requested_total_cents) }}
                        </span>
                    </div>
                </section>

                <!-- Edit mode: the same lines opened up into controls. Each
                     one saves on its own as soon as it settles, so there is
                     no "did that save?" moment with a customer waiting.

                     Its own condition, not a v-else. It used to be the else
                     of the pending-rounds section above, two blocks away
                     from the reading list it actually alternates with, so a
                     table with nothing awaiting confirmation fell into the
                     else and showed price boxes to whoever had the bill
                     open — the floor included, under the bill they were
                     already reading. -->
                <div v-if="editing && order.items.length > 0" class="space-y-2">
                    <p class="rounded-lg bg-gray-50 px-3 py-2 text-[11px] leading-relaxed text-gray-500 dark:bg-gray-800/60 dark:text-gray-400">
                        {{ t('staff.edit_bill_hint') }}
                    </p>

                    <!-- Past confirmation the bill and the paper at the pass
                         are allowed to disagree, and nothing else in the app
                         will ever mention it. Said here because this is the
                         only place the disagreement can be created. -->
                    <p
                        v-if="kitchenHasTicket"
                        class="flex gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] leading-relaxed text-amber-800 dark:border-amber-900/60 dark:bg-amber-900/20 dark:text-amber-300"
                    >
                        <NavIcon name="note" class="h-4 w-4 shrink-0" />
                        <span>{{ t('staff.edit_bill_kitchen_warning') }}</span>
                    </p>

                    <div v-for="item in order.items" :key="item.id" class="rounded-lg border border-gray-200 p-2.5 dark:border-gray-800">
                        <div class="flex items-start gap-2">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium leading-snug text-gray-900 dark:text-gray-100">{{ item.product_name }}</p>
                                <p v-if="item.modifiers.length" class="text-xs text-gray-500 dark:text-gray-400">{{ item.modifiers.join('، ') }}</p>
                            </div>
                            <button
                                type="button"
                                class="shrink-0 rounded-md p-1.5 text-gray-300 transition-colors hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/30"
                                :title="t('common.delete')"
                                @click="removeLine(item)"
                            >
                                <NavIcon name="trash" class="h-4 w-4" />
                            </button>
                        </div>

                        <div class="mt-2 flex flex-wrap items-end gap-x-4 gap-y-2">
                            <div>
                                <label class="mb-1 block text-[11px] font-medium text-gray-500 dark:text-gray-400">{{ t('staff.quantity') }}</label>
                                <div class="inline-flex items-center rounded-lg border border-gray-300 dark:border-gray-700">
                                    <button
                                        type="button"
                                        class="flex h-9 w-9 items-center justify-center rounded-s-lg text-gray-600 transition-colors hover:bg-gray-100 disabled:opacity-40 dark:text-gray-300 dark:hover:bg-gray-800"
                                        :disabled="(drafts[item.id]?.quantity ?? item.quantity) <= 1"
                                        @click="changeQuantity(item, -1)"
                                    >
                                        <NavIcon name="minus" class="h-4 w-4" />
                                    </button>
                                    <span class="w-9 text-center text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                                        {{ drafts[item.id]?.quantity ?? item.quantity }}
                                    </span>
                                    <button
                                        type="button"
                                        class="flex h-9 w-9 items-center justify-center rounded-e-lg text-gray-600 transition-colors hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
                                        @click="changeQuantity(item, 1)"
                                    >
                                        <NavIcon name="plus" class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>

                            <div v-if="drafts[item.id]">
                                <label class="mb-1 block text-[11px] font-medium text-gray-500 dark:text-gray-400">{{ t('staff.unit_price') }}</label>
                                <div class="relative">
                                    <span class="pointer-events-none absolute inset-y-0 start-2.5 flex items-center text-xs text-gray-400">{{ currency }}</span>
                                    <input
                                        v-model="drafts[item.id].price"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        dir="ltr"
                                        inputmode="decimal"
                                        class="h-9 w-28 rounded-lg border-gray-300 ps-7 text-sm tabular-nums shadow-sm focus:border-gray-500 focus:ring-gray-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200"
                                        @focus="focusedItemId = item.id"
                                        @input="onPriceInput(item)"
                                        @blur="onPriceBlur(item)"
                                        @keyup.enter="commitPrice(item)"
                                    />
                                </div>
                            </div>

                            <div class="ms-auto pb-1 text-end">
                                <p class="text-[11px] text-gray-400">{{ t('staff.total') }}</p>
                                <p class="text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ formatPrice(lineTotalCents(item)) }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-else class="flex-1 px-4 py-10 text-center">
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ t('tables.empty') }}</p>
                <p class="mt-1 text-xs text-gray-400">{{ t('staff.seats_count', { count: table.seats }) }}</p>
                <div class="mt-5"><slot name="empty-actions" /></div>
            </div>

            <!-- Footer ------------------------------------------------- -->
            <div v-if="order" class="shrink-0 border-t border-gray-100 bg-gray-50/80 p-4 dark:border-gray-800 dark:bg-gray-950/50">
                <!-- With a discount on the bill the customer is owed the
                     arithmetic: what it came to, what came off, what is
                     left. Without one, a single total line is enough. -->
                <div v-if="(order.discount_cents ?? 0) > 0 && !editing" class="mb-1.5 space-y-1 text-sm">
                    <div class="flex items-baseline justify-between text-gray-500 dark:text-gray-400">
                        <span>{{ t('staff.subtotal') }}</span>
                        <span class="tabular-nums">{{ formatPrice(subtotalCents) }}</span>
                    </div>
                    <div class="flex items-baseline justify-between font-medium text-teal-600 dark:text-teal-400">
                        <span>
                            {{ t('staff.bill_discount') }}
                            <span v-if="order.discount_reason" class="text-xs font-normal text-gray-400">({{ order.discount_reason }})</span>
                        </span>
                        <span class="tabular-nums">−{{ formatPrice(order.discount_cents) }}</span>
                    </div>
                </div>

                <div class="mb-3 flex items-baseline justify-between">
                    <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ t('staff.total') }}</span>
                    <span class="text-2xl font-bold tabular-nums text-gray-900 dark:text-gray-100">{{ formatPrice(displayedTotalCents) }}</span>
                </div>

                <p
                    v-if="order.is_paid && order.amount_paid_cents !== null && order.amount_paid_cents !== order.total_cents"
                    class="-mt-2 mb-3 text-end text-xs font-medium"
                    :class="order.amount_paid_cents < order.total_cents ? 'text-red-500' : 'text-teal-600'"
                >
                    {{ t('admin.accounting.received') }} {{ formatPrice(order.amount_paid_cents) }}
                </p>

                <!-- One press per charge, priced from the list. The line it
                     writes is an ordinary line from that moment on, so a
                     night that calls for half the usual amount is a reprice
                     on the bill rather than a second price list. -->
                <div v-if="showingServiceCharges" class="mb-3 rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
                    <p class="mb-2 text-xs font-medium text-gray-600 dark:text-gray-400">{{ t('staff.add_service_charge') }}</p>

                    <div class="space-y-1.5">
                        <button
                            v-for="charge in serviceChargeOptions"
                            :key="charge.id"
                            type="button"
                            class="flex w-full items-center justify-between gap-3 rounded-lg border border-gray-200 px-3 py-2.5 text-start transition-colors hover:border-gray-300 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800"
                            @click="addServiceCharge(charge)"
                        >
                            <span class="min-w-0 flex-1 truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ charge.name }}</span>
                            <span class="shrink-0 text-sm tabular-nums text-gray-600 dark:text-gray-300">{{ formatPrice(charge.amount_cents) }}</span>
                        </button>
                    </div>

                    <p class="mt-2 text-[11px] leading-relaxed text-gray-400">{{ t('staff.service_charge_hint') }}</p>

                    <SecondaryButton class="mt-3 w-full justify-center" type="button" @click="showingServiceCharges = false">
                        {{ t('common.cancel') }}
                    </SecondaryButton>
                </div>

                <div v-if="showingDiscountForm" class="mb-3 rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
                    <label class="text-xs font-medium text-gray-600 dark:text-gray-400">{{ t('staff.discount_amount') }}</label>

                    <div class="mt-1.5 flex flex-wrap items-center gap-2">
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 start-2.5 flex items-center text-xs text-gray-400">{{ currency }}</span>
                            <input
                                v-model="discountAmount"
                                type="number"
                                step="0.01"
                                min="0"
                                dir="ltr"
                                inputmode="decimal"
                                class="h-10 w-32 rounded-lg border-gray-300 ps-7 text-sm tabular-nums shadow-sm focus:border-gray-500 focus:ring-gray-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200"
                                @keyup.enter="confirmDiscount"
                            />
                        </div>

                        <button
                            v-for="percent in [5, 10, 15, 20]"
                            :key="percent"
                            type="button"
                            class="rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs font-medium text-gray-600 transition-colors hover:border-gray-300 hover:bg-gray-50 dark:border-gray-800 dark:text-gray-300 dark:hover:bg-gray-800"
                            @click="setDiscountPercent(percent)"
                        >{{ percent }}%</button>
                    </div>

                    <input
                        v-model="discountReason"
                        type="text"
                        maxlength="120"
                        class="mt-2 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-gray-500 focus:ring-gray-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200"
                        :placeholder="t('staff.discount_reason_example')"
                        @keyup.enter="confirmDiscount"
                    />

                    <div class="mt-3 flex flex-wrap gap-2">
                        <SecondaryButton class="flex-1 justify-center" type="button" @click="showingDiscountForm = false">{{ t('common.cancel') }}</SecondaryButton>
                        <SecondaryButton
                            v-if="(order.discount_cents ?? 0) > 0"
                            class="justify-center"
                            type="button"
                            @click="saveDiscount(0)"
                        >{{ t('staff.clear_discount') }}</SecondaryButton>
                        <PrimaryButton class="flex-1 justify-center" type="button" @click="confirmDiscount">{{ t('staff.apply') }}</PrimaryButton>
                    </div>
                </div>
                <!-- Taking the money keeps its own panel: it is the one
                     action here that needs a number typed into it. -->
                <div v-if="showingPayForm" class="mb-3 rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
                    <label class="text-xs font-medium text-gray-600 dark:text-gray-400">{{ t('staff.amount_received') }}</label>
                    <div class="mt-1.5 flex items-center gap-2">
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 start-2.5 flex items-center text-xs text-gray-400">{{ currency }}</span>
                            <input
                                v-model="payAmount"
                                type="number"
                                step="0.01"
                                min="0"
                                dir="ltr"
                                inputmode="decimal"
                                class="h-10 w-32 rounded-lg border-gray-300 ps-7 text-sm tabular-nums shadow-sm focus:border-gray-500 focus:ring-gray-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200"
                                @keyup.enter="confirmPay"
                            />
                        </div>
                        <span
                            v-if="payDifferenceCents !== 0"
                            class="text-xs font-medium"
                            :class="payDifferenceCents < 0 ? 'text-red-500' : 'text-teal-600'"
                        >
                            {{ payDifferenceCents < 0 ? t('staff.discount') : t('staff.change_due') }}: {{ formatPrice(Math.abs(payDifferenceCents)) }}
                        </span>
                    </div>
                    <!-- What is already in, when this is not the first
                         payment on this bill. -->
                    <p v-if="order.is_partially_paid" class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        {{ t('staff.already_paid', { amount: formatPrice(order.paid_so_far_cents) }) }}
                    </p>

                    <div class="mt-3 flex gap-2">
                        <SecondaryButton class="flex-1 justify-center" type="button" @click="showingPayForm = false">{{ t('common.cancel') }}</SecondaryButton>
                        <PrimaryButton class="flex-1 justify-center" type="button" @click="confirmPay">{{ t('staff.confirm_payment') }}</PrimaryButton>
                    </div>

                    <!-- Separate row, and only when the amount really is
                         less than the bill: "part" of the full amount is
                         just paying, and offering it there invites a
                         mis-tap that leaves a settled table open. -->
                    <button
                        v-if="canPayPartially"
                        type="button"
                        class="mt-2 w-full rounded-lg border border-amber-300 px-3 py-2 text-sm font-medium text-amber-700 transition hover:bg-amber-50 dark:border-amber-800 dark:text-amber-400 dark:hover:bg-amber-950"
                        @click="payPartially"
                    >
                        {{ t('staff.pay_partially') }}
                    </button>
                </div>

                <!-- One decision per row, biggest first: settle the bill,
                     then move the kitchen along, then the small stuff. -->
                <div class="space-y-2">
                    <slot name="primary-action" />

                    <PrimaryButton
                        v-if="canConfirm && order.status === 'pending'"
                        class="h-11 w-full justify-center text-sm"
                        type="button"
                        @click="post('orders.confirm')"
                    >
                        <NavIcon name="check" class="me-1.5 h-4 w-4" />{{ t('staff.confirm_order') }}
                    </PrimaryButton>

                    <div v-if="(canPay && !order.is_paid && !showingPayForm) || advanceLabel" class="flex flex-col gap-2 sm:flex-row">
                        <PrimaryButton
                            v-if="canPay && !order.is_paid && !showingPayForm"
                            class="h-11 flex-1 justify-center text-sm"
                            type="button"
                            @click="openPayForm"
                        >
                            <NavIcon name="cash" class="me-1.5 h-4 w-4" />{{ t('staff.mark_paid') }}
                        </PrimaryButton>
                        <SecondaryButton
                            v-if="advanceLabel"
                            class="h-11 flex-1 justify-center text-sm"
                            type="button"
                            @click="advance"
                        >
                            {{ advanceLabel }}
                        </SecondaryButton>
                    </div>

                    <!-- Everything that neither takes money nor moves the
                         order forward: uniform, small, and out of the way of
                         the two that do. -->
                    <div class="flex flex-wrap gap-2 pt-0.5">
                        <button v-if="canPrint" type="button" :class="CHIP" @click="post('orders.print')">
                            <NavIcon name="printer" class="h-4 w-4" />{{ t('staff.print') }}
                        </button>
                        <button v-if="canPrintCashier" type="button" :class="CHIP" @click="post('orders.print-cashier')">
                            <NavIcon name="receipt" class="h-4 w-4" />{{ t('staff.print_cashier') }}
                        </button>
                        <a v-if="canInvoice" :href="route('orders.invoice', order.id)" target="_blank" rel="noopener" :class="CHIP">
                            <NavIcon name="book" class="h-4 w-4" />{{ t('staff.invoice') }}
                        </a>
                        <button
                            v-if="canPay && !order.is_paid"
                            type="button"
                            :class="(order.discount_cents ?? 0) > 0 ? CHIP_ACTIVE : CHIP"
                            @click="openDiscountForm"
                        >
                            <NavIcon name="tag" class="h-4 w-4" />{{ t('staff.bill_discount') }}
                        </button>
                        <!-- Beside the discount, because it is the same kind
                             of decision made at the same moment: what this
                             table owes, adjusted by hand. Hidden when the
                             price list is empty — a button that opens an
                             empty sheet only asks to be pressed twice. -->
                        <button
                            v-if="canAddServiceCharge && !order.is_paid && serviceChargeOptions.length"
                            type="button"
                            :class="showingServiceCharges ? CHIP_ACTIVE : CHIP"
                            @click="openServiceCharges"
                        >
                            <NavIcon name="clock" class="h-4 w-4" />{{ t('staff.add_service_charge') }}
                        </button>
                        <button v-if="canMove && !order.is_paid" type="button" :class="CHIP" @click="emit('move')">
                            <NavIcon name="table" class="h-4 w-4" />{{ t('tables.move_table') }}
                        </button>
                        <!-- Destructive, and coloured like it. Hidden once
                             the bill is paid: at that point the correction
                             is a refund, not a cancellation. -->
                        <button
                            v-if="canCancel && !order.is_paid"
                            type="button"
                            :class="CHIP"
                            class="!border-red-200 !text-red-600 hover:!bg-red-50 dark:!border-red-900 dark:!text-red-400 dark:hover:!bg-red-950"
                            @click="confirmCancel"
                        >
                            <NavIcon name="trash" class="h-4 w-4" />{{ t('orders.cancel_order') }}
                        </button>
                        <button
                            v-if="editable"
                            type="button"
                            class="ms-auto"
                            :class="editing ? CHIP_ACTIVE : CHIP"
                            @click="editing = !editing"
                        >
                            <NavIcon :name="editing ? 'check' : 'pencil'" class="h-4 w-4" />
                            {{ editing ? t('staff.done_editing') : t('staff.edit_bill') }}
                        </button>
                    </div>

                    <slot name="extra-actions" />
                </div>
            </div>
        </div>
    </div>
</template>
