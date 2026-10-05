<script setup>
import AbsenceModal from '@/Components/Accounting/AbsenceModal.vue';
import CashMovementModal from '@/Components/Accounting/CashMovementModal.vue';
import ExpenseModal from '@/Components/Accounting/ExpenseModal.vue';
import MoneyInput from '@/Components/Accounting/MoneyInput.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatDateTime, useMoney } from '@/composables/useMoney';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * The drawer, for one day — for somebody who has never kept a book.
 *
 * The page answers the three questions asked at a till, in the order they
 * are asked: how much should be in here, what do I press when money goes in
 * or out, and how do I finish the night. Accounting words are left out on
 * purpose. Nobody behind a counter says "net movement" or "opening
 * balance"; they say "what came in", "what went out" and "what should be
 * there", so that is what the page says, and the sum is laid out as the sum
 * it is — start, plus, minus, equals — so the big number can be checked
 * rather than trusted.
 *
 * Every figure still arrives computed by Cashbox, so what is read here is the
 * number the closing will be measured against. The two exceptions are
 * previews shown while the count is being typed — the gap, and how much to
 * take home — and each is the same single subtraction DayCloser makes, done
 * here so that nobody does it on their fingers with a customer waiting.
 */
const props = defineProps({
    date: { type: String, required: true },
    today: { type: String, required: true },
    summary: { type: Object, required: true },
    movements: { type: Array, required: true },
    nextOpeningCents: { type: Number, default: 0 },
    closing: { type: Object, default: null },
    can: { type: Object, required: true },
});

const { t, te, locale } = useI18n();
const page = usePage();
const { money } = useMoney();

const showExpense = ref(false);
const showAbsence = ref(false);
const showMovement = ref(false);
const movementDirection = ref('in');
const showClose = ref(false);

const isClosed = computed(() => props.closing?.is_closed ?? false);
const isToday = computed(() => props.date === props.today);

const heroLabel = computed(() => {
    if (isClosed.value) return t('accounting.drawer_should_have_at_close');

    return isToday.value ? t('accounting.drawer_should_have_now') : t('accounting.drawer_should_have_end');
});

// ---------------------------------------------------------------
// Which day
// ---------------------------------------------------------------

/**
 * A date string moved by whole days. Done at noon UTC so a daylight-saving
 * change can never land the result on the same day or skip one.
 */
function shiftDate(iso, days) {
    const moment = new Date(`${iso}T12:00:00Z`);
    moment.setUTCDate(moment.getUTCDate() + days);

    return moment.toISOString().slice(0, 10);
}

function goToDate(date) {
    if (!date) return;

    // A fresh page per day: a half-typed count belongs to the day it was
    // typed against, not to the next one somebody flicks to.
    router.get(route('admin.accounting.cashbox'), { date }, { preserveScroll: true, replace: true });
}

// "Sunday 5 October 2026" rather than "2026-10-05": the person reading it
// thinks in days of the week. Latin digits, like every other figure here.
const dateLabel = computed(() => {
    try {
        return new Intl.DateTimeFormat(`${locale.value}-u-nu-latn`, {
            weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC',
        }).format(new Date(`${props.date}T12:00:00Z`));
    } catch {
        return props.date;
    }
});

// ---------------------------------------------------------------
// Recording money in and out
// ---------------------------------------------------------------

function openMovement(direction) {
    movementDirection.value = direction;
    showMovement.value = true;
}

/**
 * The four things a person can do to the drawer, as buttons that say what
 * happened. Only the ones this person may do on this day are offered — a
 * button that fails on save teaches people the app is unreliable, not that
 * the day is closed.
 */
const actions = computed(() => [
    {
        key: 'in', icon: '➕', tone: 'green',
        title: 'drawer_put_in', hint: 'drawer_put_in_hint',
        show: props.can.collect && !isClosed.value,
        run: () => openMovement('in'),
    },
    {
        key: 'out', icon: '➖', tone: 'red',
        title: 'drawer_took_out', hint: 'drawer_took_out_hint',
        show: props.can.collect && !isClosed.value,
        run: () => openMovement('out'),
    },
    {
        key: 'expense', icon: '🧾', tone: 'red',
        title: 'drawer_paid_expense', hint: 'drawer_paid_expense_hint',
        show: !isClosed.value,
        run: () => (showExpense.value = true),
    },
    {
        // Still offered on a closed day: an absence comes off a wage and
        // never touches the drawer, so closing the drawer does not stop it.
        key: 'absence', icon: '👤', tone: 'gray',
        title: 'drawer_absence', hint: 'drawer_absence_hint',
        show: true,
        run: () => (showAbsence.value = true),
    },
].filter((action) => action.show));

const actionTones = {
    green: 'border-green-200 hover:border-green-400 hover:bg-green-50 dark:border-green-900/50 dark:hover:bg-green-950/30',
    red: 'border-red-200 hover:border-red-400 hover:bg-red-50 dark:border-red-900/50 dark:hover:bg-red-950/30',
    gray: 'border-gray-200 hover:border-gray-400 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800',
};

// ---------------------------------------------------------------
// Closing the day
// ---------------------------------------------------------------

const closeForm = useForm({ date: props.date, counted: '', notes: '' });

/**
 * The count as cents, while it is being typed.
 *
 * The same conversion the server makes on save — round(value × 100), see
 * NormalisesMoney — so the gap shown before pressing the button is the gap
 * that gets recorded after it. The typed text is still what is sent.
 */
const countedCents = computed(() => {
    const raw = String(closeForm.counted ?? '').trim();

    if (raw === '' || Number.isNaN(Number(raw))) return null;

    return Math.round(Number(raw) * 100);
});

// counted − expected, exactly as DayCloser stores it.
const liveDifference = computed(() => (countedCents.value === null
    ? null
    : countedCents.value - props.summary.balance_cents));

function stateOf(difference) {
    if (difference === null) return null;
    if (difference === 0) return 'balanced';

    return difference > 0 ? 'over' : 'short';
}

const liveState = computed(() => stateOf(liveDifference.value));

/**
 * What to do with the money once the day is closed: everything above
 * tomorrow's change goes home, the change stays. Said as two amounts so
 * nobody has to subtract at the end of a long shift.
 */
function takeAndLeave(countedCents) {
    if (countedCents === null || countedCents === undefined) return null;

    const take = countedCents - props.nextOpeningCents;

    return take > 0
        ? t('accounting.drawer_take_leave', { take: money(take), leave: money(props.nextOpeningCents) })
        : t('accounting.drawer_leave_all', { leave: money(props.nextOpeningCents) });
}

const liveTakeAndLeave = computed(() => takeAndLeave(countedCents.value));

const resultStyles = {
    balanced: { icon: '✅', box: 'border-green-300 bg-green-50 text-green-800 dark:border-green-800 dark:bg-green-950/40 dark:text-green-300' },
    short: { icon: '⚠️', box: 'border-red-300 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-300' },
    over: { icon: 'ℹ️', box: 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300' },
};

function liveResultText() {
    if (liveState.value === 'balanced') return t('accounting.drawer_result_exact');

    return t(`accounting.drawer_result_${liveState.value}`, { amount: money(Math.abs(liveDifference.value)) });
}

function submitClose() {
    // A gap is allowed — it is a real outcome and it gets recorded — but it
    // is never closed on by accident. The usual cause is a miscount, and
    // this is the last moment a second count is free.
    if (liveState.value && liveState.value !== 'balanced'
        && !confirm(t(`accounting.drawer_confirm_${liveState.value}`, { amount: money(Math.abs(liveDifference.value)) }))) {
        return;
    }

    closeForm.date = props.date;
    closeForm.post(route('admin.accounting.cashbox.close'), {
        preserveScroll: true,
        onSuccess: () => { showClose.value = false; closeForm.reset(); },
    });
}

function reopen() {
    if (!confirm(t('accounting.confirm_reopen'))) return;

    router.post(route('admin.accounting.cashbox.reopen'), { date: props.date }, { preserveScroll: true });
}

const closedTitle = computed(() => {
    if (!props.closing) return '';

    const state = props.closing.difference_state;

    return state === 'balanced'
        ? t('accounting.drawer_closed_balanced')
        : t(`accounting.drawer_closed_${state}`, { amount: money(Math.abs(props.closing.difference_cents)) });
});

// Rasterising an Arabic ticket is mPDF then pdftoppm then ESC/POS, which
// takes a moment on the machine in the café — long enough that without this
// the button looks dead and gets pressed again, printing the day twice.
const printing = ref(false);

function printDay() {
    router.post(route('admin.accounting.cashbox.print'), { date: props.date }, {
        preserveScroll: true,
        onStart: () => (printing.value = true),
        onFinish: () => (printing.value = false),
    });
}

// ---------------------------------------------------------------
// For information
// ---------------------------------------------------------------

/**
 * Figures somebody will ask about that do not change what is in the
 * drawer. Kept in their own, quieter box and labelled as such, because a
 * number on a cash screen that is not part of the cash is exactly the one
 * that gets subtracted by hand.
 */
const infoRows = computed(() => [
    { key: 'sales', icon: '🧾', label: 'drawer_total_sales', value: money(props.summary.total_takings_cents), show: true },
    { key: 'orders', icon: '🍽️', label: 'drawer_orders_paid', value: String(props.summary.orders_count), show: true },
    {
        key: 'card', icon: '💳', label: 'drawer_card', hint: 'drawer_card_hint',
        value: money(props.summary.card_collected_cents), show: props.summary.card_collected_cents !== 0,
    },
    {
        key: 'discounts', icon: '🏷️', label: 'drawer_discounts', hint: 'drawer_discounts_hint',
        value: money(props.summary.bill_discounts_cents), show: props.summary.bill_discounts_cents > 0,
    },
    {
        key: 'staff', icon: '👤', label: 'drawer_staff_deductions', hint: 'drawer_staff_deductions_hint',
        value: money(props.summary.staff_deductions_cents), show: props.summary.staff_deductions_cents > 0,
    },
].filter((row) => row.show));

// ---------------------------------------------------------------
// What happened
// ---------------------------------------------------------------

/**
 * Newest first — the line somebody comes looking for is nearly always the
 * thing that just happened — and only the latest few until asked, so a busy
 * evening of cash bills does not push the page into a scroll nobody reads.
 */
const LOG_PREVIEW = 8;
const showWholeLog = ref(false);

const newestFirst = computed(() => [...props.movements].reverse());

const visibleLog = computed(() => (showWholeLog.value
    ? newestFirst.value
    : newestFirst.value.slice(0, LOG_PREVIEW)));

// 24-hour: "08:00 PM" inside an Arabic line gets its halves reordered by
// the bidi algorithm, and "20:00" cannot be.
function timeOf(iso) {
    if (!iso) return '';

    return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
}

// The plain name where there is one, the ledger's own name otherwise — a
// staff advance, say, which is recorded elsewhere but still moves the drawer.
function typeName(kind) {
    const friendly = `accounting.drawer_type_${kind}`;

    return te(friendly) ? t(friendly) : t(`accounting.movement_${kind}`);
}

function logTitle(movement) {
    if (movement.kind === 'order') {
        return movement.description
            ? `${t('accounting.drawer_order_paid')} · ${movement.description}`
            : t('accounting.drawer_order_paid');
    }

    if (movement.kind === 'expense') return movement.label || t('accounting.expense');

    return movement.label || typeName(movement.kind);
}

/**
 * The small line under each entry, as separate parts. The template isolates
 * each one: a time, a bill number and a name side by side are three
 * different directions of text, and run together they are reordered into
 * something nobody wrote.
 */
function logDetail(movement) {
    const parts = [timeOf(movement.at)];

    if (movement.kind === 'order') {
        parts.push(`#${movement.label}`);
    } else if (movement.kind === 'expense') {
        parts.push(t('accounting.expense'));
        if (movement.description) parts.push(movement.description);
    } else {
        parts.push(typeName(movement.kind));
        if (movement.description) parts.push(movement.description);
    }

    if (movement.by) parts.push(movement.by);

    return parts.filter(Boolean);
}

function deleteMovement(movement) {
    if (!confirm(t('accounting.confirm_delete'))) return;

    router.delete(route('admin.accounting.cashbox.movements.destroy', movement.movement_id), { preserveScroll: true });
}
</script>

<template>
    <Head :title="$t('accounting.cashbox')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('accounting.cashbox') }}</template>

        <div class="mx-auto max-w-4xl space-y-5">
            <!-- 1. Which day this is -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="rounded-lg border border-gray-200 bg-white p-2 text-gray-600 transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-800"
                        :title="$t('accounting.drawer_prev_day')"
                        :aria-label="$t('accounting.drawer_prev_day')"
                        @click="goToDate(shiftDate(date, -1))"
                    >
                        <svg class="h-5 w-5 rtl:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 01-.02 1.06L8.832 10l3.938 3.71a.75.75 0 11-1.04 1.08l-4.5-4.25a.75.75 0 010-1.08l4.5-4.25a.75.75 0 011.06.02z" clip-rule="evenodd" />
                        </svg>
                    </button>

                    <div class="min-w-0">
                        <p class="text-base font-bold text-gray-900 dark:text-gray-100">{{ dateLabel }}</p>
                        <div class="flex items-center gap-2">
                            <TextInput :model-value="date" type="date" dir="ltr" class="py-0.5 text-xs" @update:model-value="goToDate" />
                            <button v-if="!isToday" type="button" class="text-xs font-medium hover:underline"
                                    :style="{ color: 'var(--brand-primary)' }" @click="goToDate(today)">
                                {{ $t('accounting.drawer_back_to_today') }}
                            </button>
                        </div>
                    </div>

                    <!-- No tomorrow: nothing has happened there yet, and a
                         page of zeros invites somebody to start entering
                         things against the wrong day. -->
                    <button
                        type="button"
                        class="rounded-lg border border-gray-200 bg-white p-2 text-gray-600 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-800"
                        :title="$t('accounting.drawer_next_day')"
                        :aria-label="$t('accounting.drawer_next_day')"
                        :disabled="date >= today"
                        @click="goToDate(shiftDate(date, 1))"
                    >
                        <svg class="h-5 w-5 rtl:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                        </svg>
                    </button>
                </div>

                <div class="flex items-center gap-3">
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold"
                        :class="isClosed
                            ? 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200'
                            : 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400'"
                    >
                        <span aria-hidden="true">{{ isClosed ? '🔒' : '🟢' }}</span>
                        {{ isClosed ? $t('accounting.drawer_status_closed') : $t('accounting.drawer_status_open') }}
                    </span>
                    <Link
                        :href="route('admin.accounting.cashbox.closings')"
                        class="text-sm text-gray-500 underline hover:text-gray-800 dark:hover:text-gray-200"
                    >{{ $t('accounting.drawer_history') }}</Link>
                </div>
            </div>

            <!-- 2. The one number, and how it was reached -->
            <section class="rounded-2xl border-2 border-blue-200 bg-blue-50 p-5 dark:border-blue-900/60 dark:bg-blue-950/30">
                <!-- "Now" only while it is true. Once the day is closed the
                     owner has taken everything above the change, and a
                     heading still saying "should be in the drawer now" over
                     the night's whole figure reads as money gone missing. -->
                <p class="text-sm font-semibold text-blue-900 dark:text-blue-200">
                    💰 {{ heroLabel }}
                </p>
                <p class="mt-1 text-4xl font-extrabold tabular-nums text-blue-800 sm:text-5xl dark:text-blue-300">
                    <span dir="ltr">{{ money(summary.balance_cents) }}</span>
                </p>
                <p class="mt-1 text-xs text-blue-900/70 dark:text-blue-200/70">{{ $t('accounting.drawer_cash_only') }}</p>

                <!-- The sum written out as a sum. A big number on its own has
                     to be trusted; this one can be checked line by line by
                     anybody who can add. -->
                <div class="mt-5 rounded-xl bg-white/80 p-4 dark:bg-gray-900/60">
                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400">{{ $t('accounting.drawer_how_title') }}</p>

                    <dl class="mt-3 space-y-3 text-sm">
                        <div class="flex items-start justify-between gap-3">
                            <dt class="flex items-start gap-2">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs dark:bg-gray-800" aria-hidden="true">🌅</span>
                                <span>
                                    <span class="block font-medium text-gray-900 dark:text-gray-100">{{ $t('accounting.drawer_start') }}</span>
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $t('accounting.drawer_start_hint') }}</span>
                                </span>
                            </dt>
                            <dd class="font-semibold tabular-nums text-gray-900 dark:text-gray-100" dir="ltr">{{ money(summary.opening_cents) }}</dd>
                        </div>

                        <div>
                            <div class="flex items-start justify-between gap-3">
                                <dt class="flex items-center gap-2">
                                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-green-100 text-sm font-bold text-green-700 dark:bg-green-900/40 dark:text-green-400" aria-hidden="true">+</span>
                                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $t('accounting.drawer_in') }}</span>
                                </dt>
                                <dd class="font-semibold tabular-nums text-green-700 dark:text-green-400" dir="ltr">+{{ money(summary.total_in_cents) }}</dd>
                            </div>
                            <div class="ms-8 mt-1 space-y-0.5 text-xs text-gray-500 dark:text-gray-400">
                                <div class="flex justify-between gap-3">
                                    <span>{{ $t('accounting.drawer_in_orders') }}</span>
                                    <span class="tabular-nums" dir="ltr">{{ money(summary.collected_cents) }}</span>
                                </div>
                                <div v-if="summary.manual_in_cents > 0" class="flex justify-between gap-3">
                                    <span>{{ $t('accounting.drawer_in_manual') }}</span>
                                    <span class="tabular-nums" dir="ltr">{{ money(summary.manual_in_cents) }}</span>
                                </div>
                            </div>
                        </div>

                        <div>
                            <div class="flex items-start justify-between gap-3">
                                <dt class="flex items-center gap-2">
                                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-red-100 text-sm font-bold text-red-700 dark:bg-red-900/40 dark:text-red-400" aria-hidden="true">−</span>
                                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $t('accounting.drawer_out') }}</span>
                                </dt>
                                <dd class="font-semibold tabular-nums text-red-700 dark:text-red-400" dir="ltr">−{{ money(summary.total_out_cents) }}</dd>
                            </div>
                            <div v-if="summary.total_out_cents > 0" class="ms-8 mt-1 space-y-0.5 text-xs text-gray-500 dark:text-gray-400">
                                <div v-if="summary.expenses_cents > 0" class="flex justify-between gap-3">
                                    <span>{{ $t('accounting.drawer_out_expenses') }}</span>
                                    <span class="tabular-nums" dir="ltr">{{ money(summary.expenses_cents) }}</span>
                                </div>
                                <div v-if="summary.manual_out_cents > 0" class="flex justify-between gap-3">
                                    <span>{{ $t('accounting.drawer_out_manual') }}</span>
                                    <span class="tabular-nums" dir="ltr">{{ money(summary.manual_out_cents) }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center justify-between gap-3 border-t border-gray-200 pt-3 dark:border-gray-700">
                            <dt class="flex items-center gap-2">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-blue-100 text-sm font-bold text-blue-700 dark:bg-blue-900/40 dark:text-blue-300" aria-hidden="true">=</span>
                                <span class="font-bold text-gray-900 dark:text-gray-100">{{ $t('accounting.drawer_equals') }}</span>
                            </dt>
                            <dd class="text-base font-extrabold tabular-nums text-blue-800 dark:text-blue-300" dir="ltr">{{ money(summary.balance_cents) }}</dd>
                        </div>
                    </dl>
                </div>
            </section>

            <!-- 3. Something happened at the drawer -->
            <section>
                <h3 class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-300">{{ $t('accounting.drawer_actions_title') }}</h3>

                <p v-if="isClosed" class="mb-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-300">
                    🔒 {{ $t('accounting.drawer_locked_note') }}
                </p>

                <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <button
                        v-for="action in actions"
                        :key="action.key"
                        type="button"
                        class="flex flex-col items-start gap-1 rounded-xl border-2 bg-white p-4 text-start transition dark:bg-gray-900"
                        :class="actionTones[action.tone]"
                        @click="action.run"
                    >
                        <span class="text-2xl" aria-hidden="true">{{ action.icon }}</span>
                        <span class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ $t(`accounting.${action.title}`) }}</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $t(`accounting.${action.hint}`) }}</span>
                    </button>
                </div>
            </section>

            <!-- 4. Finishing the night -->
            <section class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 class="text-base font-bold text-gray-900 dark:text-gray-100">🔒 {{ $t('accounting.drawer_close_title') }}</h3>

                    <!-- Here, where the counting happens: the paper is what the
                         drawer is counted against, before the close, and the
                         record of it, after. -->
                    <SecondaryButton type="button" :disabled="printing" @click="printDay">
                        🖨️ {{ printing ? $t('accounting.loading') : $t('accounting.print_day') }}
                    </SecondaryButton>
                </div>

                <!-- Still open: the guided count. -->
                <template v-if="!isClosed && can.close">
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $t('accounting.drawer_close_intro') }}</p>

                    <PrimaryButton v-if="!showClose" type="button" class="mt-4 w-full justify-center py-3 text-base sm:w-auto" @click="showClose = true">
                        {{ $t('accounting.drawer_start_close') }}
                    </PrimaryButton>

                    <form v-else class="mt-4 space-y-4" @submit.prevent="submitClose">
                        <div class="max-w-sm">
                            <MoneyInput id="counted" v-model="closeForm.counted" :label="$t('accounting.drawer_step_count')" required autofocus />
                            <InputError class="mt-1" :message="closeForm.errors.counted_cents || closeForm.errors.date" />
                        </div>

                        <!-- The answer while the number is still being typed:
                             the moment to recount is before pressing close,
                             not after reading a report about it. -->
                        <div v-if="liveState" class="rounded-xl border-2 p-4" :class="resultStyles[liveState].box">
                            <p class="text-lg font-bold">
                                <span aria-hidden="true">{{ resultStyles[liveState].icon }}</span>
                                {{ liveResultText() }}
                            </p>
                            <p v-if="liveState !== 'balanced'" class="mt-1 text-sm">{{ $t('accounting.drawer_recount') }}</p>
                        </div>

                        <p v-if="liveTakeAndLeave" class="rounded-lg bg-gray-50 px-4 py-3 text-sm font-medium text-gray-800 dark:bg-gray-800/60 dark:text-gray-200">
                            💵 {{ liveTakeAndLeave }}
                        </p>

                        <div>
                            <InputLabel for="closing-notes" :value="$t('accounting.drawer_notes_label')" />
                            <textarea
                                id="closing-notes"
                                v-model="closeForm.notes"
                                rows="2"
                                class="focus-brand mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                            />
                        </div>

                        <div class="flex flex-wrap justify-end gap-3">
                            <SecondaryButton type="button" @click="showClose = false">{{ $t('common.cancel') }}</SecondaryButton>
                            <PrimaryButton :disabled="closeForm.processing || countedCents === null" class="px-6">
                                {{ closeForm.processing ? $t('accounting.loading') : '✅ ' + $t('accounting.drawer_close_button') }}
                            </PrimaryButton>
                        </div>
                    </form>
                </template>

                <!-- Closed: how it went, in one sentence. -->
                <div v-if="closing && isClosed" class="mt-4 rounded-xl border-2 p-4" :class="resultStyles[closing.difference_state].box">
                    <p class="text-lg font-bold">
                        <span aria-hidden="true">{{ resultStyles[closing.difference_state].icon }}</span>
                        {{ closedTitle }}
                    </p>
                    <p class="mt-1 text-sm">
                        {{ $t('accounting.drawer_closed_counted', { counted: money(closing.counted_cents), expected: money(closing.expected_cents) }) }}
                    </p>
                    <!-- Still useful after the close: it is the instruction
                         for the drawer that is sitting there right now. -->
                    <p v-if="isToday" class="mt-2 text-sm font-medium">💵 {{ takeAndLeave(closing.counted_cents) }}</p>
                    <p v-if="closing.notes" class="mt-2 text-sm">📝 {{ closing.notes }}</p>
                    <!-- The moment isolated in <bdi>: a date and a time inside
                         an Arabic sentence otherwise come out reordered. -->
                    <i18n-t v-if="closing.closed_by_name" keypath="accounting.drawer_closed_by" tag="p" class="mt-2 text-xs opacity-80">
                        <template #name>{{ closing.closed_by_name }}</template>
                        <template #at><bdi>{{ formatDateTime(closing.closed_at) }}</bdi></template>
                    </i18n-t>
                </div>

                <!-- Reopened once, and not yet closed again. -->
                <p v-if="closing && !isClosed && closing.reopened_by_name" class="mt-3 text-xs text-amber-700 dark:text-amber-400">
                    {{ $t('accounting.reopened_by') }}: {{ closing.reopened_by_name }} · {{ formatDateTime(closing.reopened_at) }}
                </p>

                <button
                    v-if="can.reopen && isClosed"
                    type="button"
                    class="mt-3 text-xs text-gray-500 underline hover:text-gray-800 dark:hover:text-gray-200"
                    @click="reopen"
                >{{ $t('accounting.drawer_reopen') }}</button>
            </section>

            <!-- 5. For information -->
            <section class="rounded-2xl border border-dashed border-gray-300 p-5 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-600 dark:text-gray-400">ℹ️ {{ $t('accounting.drawer_info_title') }}</h3>

                <ul class="mt-3 grid gap-3 sm:grid-cols-2">
                    <li v-for="row in infoRows" :key="row.key" class="flex items-start justify-between gap-3">
                        <span class="flex items-start gap-2">
                            <span aria-hidden="true">{{ row.icon }}</span>
                            <span>
                                <span class="block text-sm text-gray-800 dark:text-gray-200">{{ $t(`accounting.${row.label}`) }}</span>
                                <span v-if="row.hint" class="block text-xs text-gray-500 dark:text-gray-400">{{ $t(`accounting.${row.hint}`) }}</span>
                            </span>
                        </span>
                        <span class="text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100" dir="ltr">{{ row.value }}</span>
                    </li>
                </ul>
            </section>

            <!-- 6. Everything behind the big number -->
            <section class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <h3 class="border-b border-gray-200 px-5 py-3 text-base font-bold text-gray-900 dark:border-gray-800 dark:text-gray-100">
                    📋 {{ $t('accounting.drawer_log_title') }}
                    <span v-if="movements.length" class="text-sm font-normal text-gray-400">({{ movements.length }})</span>
                </h3>

                <div v-if="movements.length === 0" class="px-5 py-10 text-center text-sm text-gray-400">
                    {{ $t('accounting.drawer_log_empty') }}
                </div>

                <ul v-else class="divide-y divide-gray-100 dark:divide-gray-800">
                    <li v-for="movement in visibleLog" :key="movement.id" class="flex items-center gap-3 px-5 py-3">
                        <span
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-base font-bold"
                            :class="movement.direction === 'in'
                                ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400'
                                : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400'"
                            aria-hidden="true"
                        >{{ movement.direction === 'in' ? '+' : '−' }}</span>

                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ logTitle(movement) }}</div>
                            <div class="truncate text-xs text-gray-500 dark:text-gray-400">
                                <template v-for="(part, index) in logDetail(movement)" :key="index">
                                    <span v-if="index" aria-hidden="true"> · </span><bdi>{{ part }}</bdi>
                                </template>
                            </div>
                        </div>

                        <span
                            class="shrink-0 text-sm font-bold tabular-nums"
                            :class="movement.direction === 'in' ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400'"
                            dir="ltr"
                        >{{ movement.direction === 'in' ? '+' : '−' }}{{ money(movement.amount_cents) }}</span>

                        <button
                            v-if="movement.deletable && can.collect && !isClosed"
                            type="button"
                            class="shrink-0 text-xs text-red-500 hover:text-red-700"
                            @click="deleteMovement(movement)"
                        >{{ $t('common.delete') }}</button>
                    </li>
                </ul>

                <div v-if="movements.length > LOG_PREVIEW" class="border-t border-gray-100 px-5 py-3 text-center dark:border-gray-800">
                    <button type="button" class="text-sm font-medium hover:underline" :style="{ color: 'var(--brand-primary)' }"
                            @click="showWholeLog = !showWholeLog">
                        {{ showWholeLog ? $t('accounting.drawer_log_show_less') : $t('accounting.drawer_log_show_all', { n: movements.length }) }}
                    </button>
                </div>
            </section>
        </div>

        <ExpenseModal
            :show="showExpense"
            :categories="page.props.expenseCategories ?? []"
            :staff="page.props.staffOptions ?? []"
            :today="date"
            @close="showExpense = false"
        />
        <AbsenceModal
            :show="showAbsence"
            :categories="page.props.absenceCategories ?? []"
            :staff="page.props.staffOptions ?? []"
            :today="date"
            @close="showAbsence = false"
        />
        <CashMovementModal
            :show="showMovement"
            :today="date"
            :initial-direction="movementDirection"
            @close="showMovement = false"
        />
    </AuthenticatedLayout>
</template>
