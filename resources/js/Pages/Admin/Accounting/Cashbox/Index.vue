<script setup>
import AbsenceModal from '@/Components/Accounting/AbsenceModal.vue';
import CashMovementModal from '@/Components/Accounting/CashMovementModal.vue';
import ExpenseModal from '@/Components/Accounting/ExpenseModal.vue';
import MoneyInput from '@/Components/Accounting/MoneyInput.vue';
import QuickAddMenu from '@/Components/Accounting/QuickAddMenu.vue';
import StatCard from '@/Components/Accounting/StatCard.vue';
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
 * The drawer, for one day.
 *
 * Nothing on this page does arithmetic. Every figure arrives computed by
 * Cashbox, so what the manager reads here is the same number the closing
 * will be measured against — the alternative is a screen that adds up
 * slightly differently from the record it produces.
 */
const props = defineProps({
    date: { type: String, required: true },
    today: { type: String, required: true },
    summary: { type: Object, required: true },
    movements: { type: Array, required: true },
    closing: { type: Object, default: null },
    can: { type: Object, required: true },
});

const { t } = useI18n();
const page = usePage();
const { money, signed } = useMoney();

const showExpense = ref(false);
const showAbsence = ref(false);
const showMovement = ref(false);
const movementDirection = ref('in');
const showClose = ref(false);

const isClosed = computed(() => props.closing?.is_closed ?? false);

function goToDate(date) {
    router.get(route('admin.accounting.cashbox'), { date }, { preserveState: true, replace: true });
}

const closeForm = useForm({ date: props.date, counted: '', notes: '' });

function submitClose() {
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

function deleteMovement(movement) {
    if (!confirm(t('accounting.confirm_delete'))) return;

    router.delete(route('admin.accounting.cashbox.movements.destroy', movement.movement_id), { preserveScroll: true });
}

function openMovement(direction) {
    movementDirection.value = direction;
    showMovement.value = true;
}

const differenceTone = computed(() => {
    if (!props.closing) return 'neutral';

    return props.closing.difference_state === 'balanced' ? 'balance' : 'warn';
});

function movementLabel(movement) {
    if (movement.kind === 'order') return t('accounting.movement_order');
    if (movement.kind === 'expense') return t('accounting.expense');

    return t(`accounting.movement_${movement.kind}`);
}
</script>

<template>
    <Head :title="$t('accounting.cashbox')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('accounting.cashbox') }}</template>

        <!-- Date picker, state badge, and the one button that reaches every
             entry in the system. -->
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-3">
                <TextInput
                    :model-value="date"
                    type="date"
                    dir="ltr"
                    class="text-sm"
                    @update:model-value="goToDate"
                />
                <span
                    class="inline-flex rounded-full px-3 py-1 text-xs font-semibold"
                    :class="isClosed
                        ? 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200'
                        : 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400'"
                >
                    {{ isClosed ? $t('accounting.day_closed_badge') : $t('accounting.day_open_badge') }}
                </span>
                <Link
                    :href="route('admin.accounting.cashbox.closings')"
                    class="text-sm text-gray-500 underline hover:text-gray-800 dark:hover:text-gray-200"
                >{{ $t('accounting.closings') }}</Link>
            </div>

            <QuickAddMenu
                :can="{ collect: can.collect && !isClosed, expense: !isClosed, absence: true }"
                @collection="openMovement('in')"
                @withdrawal="openMovement('out')"
                @expense="showExpense = true"
                @absence="showAbsence = true"
            />
        </div>

        <div v-if="isClosed" class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-300">
            {{ $t('accounting.day_is_closed_warning') }}
        </div>

        <!-- The five figures, in the order the equation reads. -->
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <StatCard :label="$t('accounting.opening_balance')" :value="money(summary.opening_cents)" tone="neutral" icon="🏦" />
            <StatCard :label="$t('accounting.collected_today')" :value="signed(summary.total_in_cents)" tone="in" icon="📥" />
            <StatCard :label="$t('accounting.expenses_today')" :value="signed(-summary.total_out_cents)" tone="out" icon="📤" />
            <StatCard :label="$t('accounting.staff_deductions')" :value="money(summary.staff_deductions_cents)" tone="warn" icon="🏷️" />
            <StatCard :label="$t('accounting.current_balance')" :value="money(summary.balance_cents)" tone="balance" icon="💰" />
        </div>

        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ $t('accounting.deductions_note') }}</p>

        <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <StatCard :label="$t('accounting.net_movement')" :value="signed(summary.net_movement_cents)" tone="neutral" icon="📊" />
            <StatCard :label="$t('accounting.orders_count')" :value="String(summary.orders_count)" tone="neutral" />
            <StatCard :label="$t('accounting.count')" :value="String(summary.expenses_count)" tone="neutral" />
            <StatCard :label="$t('accounting.bill_discounts')" :value="money(summary.bill_discounts_cents)" tone="neutral" />
        </div>

        <!-- Closing -->
        <div class="mt-6 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ $t('accounting.close_day') }}</h3>

                <div class="flex gap-2">
                    <!-- Beside the closing controls, not in the page header:
                         the paper is what a manager counts the drawer
                         against, so it belongs where the counting happens.
                         Available whether or not the day is closed — before,
                         it is the sheet you count with; after, it is the
                         record, and it prints the counted figure and the gap
                         once there is one. -->
                    <SecondaryButton type="button" :disabled="printing" @click="printDay">
                        {{ printing ? $t('accounting.loading') : $t('accounting.print_day') }}
                    </SecondaryButton>
                    <PrimaryButton v-if="can.close && !isClosed" type="button" @click="showClose = !showClose">
                        {{ $t('accounting.close_day') }}
                    </PrimaryButton>
                    <SecondaryButton v-if="can.reopen && isClosed" type="button" @click="reopen">
                        {{ $t('accounting.reopen_day') }}
                    </SecondaryButton>
                </div>
            </div>

            <!-- The one number a person supplies. Everything beside it is
                 derived and shown read-only. -->
            <form v-if="showClose && !isClosed" class="mt-4 grid gap-4 sm:grid-cols-2" @submit.prevent="submitClose">
                <div>
                    <MoneyInput id="counted" v-model="closeForm.counted" :label="$t('accounting.counted_amount')" required autofocus />
                    <p class="mt-1 text-xs text-gray-500">{{ $t('accounting.counted_hint') }}</p>
                    <InputError class="mt-1" :message="closeForm.errors.counted_cents || closeForm.errors.date" />
                </div>

                <div>
                    <InputLabel for="closing-notes" :value="$t('accounting.closing_notes')" />
                    <textarea
                        id="closing-notes"
                        v-model="closeForm.notes"
                        rows="3"
                        class="focus-brand mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                    />
                </div>

                <div class="sm:col-span-2 flex items-center justify-between rounded-lg bg-gray-50 px-4 py-3 dark:bg-gray-800/60">
                    <span class="text-sm text-gray-600 dark:text-gray-400">{{ $t('accounting.expected_amount') }}</span>
                    <span class="text-lg font-bold tabular-nums text-gray-900 dark:text-gray-100" dir="ltr">
                        {{ money(summary.balance_cents) }}
                    </span>
                </div>

                <div class="sm:col-span-2 flex justify-end gap-3">
                    <SecondaryButton type="button" @click="showClose = false">{{ $t('common.cancel') }}</SecondaryButton>
                    <PrimaryButton :disabled="closeForm.processing">
                        {{ closeForm.processing ? $t('accounting.loading') : $t('accounting.close_day') }}
                    </PrimaryButton>
                </div>
            </form>

            <!-- What was signed off, if anything. -->
            <div v-if="closing" class="mt-4 grid gap-3 sm:grid-cols-3">
                <StatCard :label="$t('accounting.expected_amount')" :value="money(closing.expected_cents)" tone="neutral" />
                <StatCard :label="$t('accounting.counted_amount')" :value="money(closing.counted_cents)" tone="neutral" />
                <StatCard
                    :label="$t(`accounting.difference_${closing.difference_state}`)"
                    :value="signed(closing.difference_cents)"
                    :tone="differenceTone"
                />

                <p class="text-xs text-gray-500 sm:col-span-3 dark:text-gray-400">
                    <span v-if="closing.closed_by_name">
                        {{ $t('accounting.closed_by') }}: {{ closing.closed_by_name }} · {{ formatDateTime(closing.closed_at) }}
                    </span>
                    <span v-if="closing.reopened_by_name" class="ms-2 text-amber-600 dark:text-amber-400">
                        · {{ $t('accounting.reopened_by') }}: {{ closing.reopened_by_name }} · {{ formatDateTime(closing.reopened_at) }}
                    </span>
                    <span v-if="closing.notes" class="block">{{ closing.notes }}</span>
                </p>
            </div>
        </div>

        <!-- Every line behind the balance, so a figure that looks wrong can
             be traced without leaving the page. -->
        <div class="mt-6 rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <h3 class="border-b border-gray-200 px-4 py-3 text-base font-semibold text-gray-900 dark:border-gray-800 dark:text-gray-100">
                {{ $t('accounting.movements') }}
            </h3>

            <div v-if="movements.length === 0" class="px-4 py-10 text-center text-sm text-gray-400">
                {{ $t('accounting.no_movements') }}
            </div>

            <ul v-else class="divide-y divide-gray-100 dark:divide-gray-800">
                <li v-for="movement in movements" :key="movement.id" class="flex items-center gap-3 px-4 py-3">
                    <span
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-bold"
                        :class="movement.direction === 'in'
                            ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400'
                            : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400'"
                    >{{ movement.direction === 'in' ? '+' : '−' }}</span>

                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">
                            {{ movement.label || movementLabel(movement) }}
                        </div>
                        <div class="truncate text-xs text-gray-500 dark:text-gray-400">
                            {{ movementLabel(movement) }}
                            <span v-if="movement.description"> · {{ movement.description }}</span>
                            <span v-if="movement.by"> · {{ movement.by }}</span>
                        </div>
                    </div>

                    <span
                        class="shrink-0 text-sm font-semibold tabular-nums"
                        :class="movement.direction === 'in'
                            ? 'text-green-700 dark:text-green-400'
                            : 'text-red-700 dark:text-red-400'"
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
