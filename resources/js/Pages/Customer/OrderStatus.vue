<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import NavIcon from '@/Components/NavIcon.vue';
import { useOrderStatus } from '@/composables/useOrderStatus';

const props = defineProps({
    order: {
        type: Object,
        required: true,
    },
});

const page = usePage();
const { t } = useI18n();
const { statusBadgeClasses } = useOrderStatus();

function formatPrice(cents) {
    return `${page.props.branding.currency_symbol}${(cents / 100).toFixed(2)}`;
}

const itemCount = computed(() => props.order.items.reduce((sum, item) => sum + item.quantity, 0));

/**
 * Until a waiter confirms the order, a guest can change what they ordered
 * from this phone — their own share of each line and nobody else's. The
 * server decides both (`editable`, `mine`); this only draws the buttons.
 *
 * On a line two guests ordered into, the stepper moves this guest's share
 * while showing the line's total, so taking their one juice off a line of
 * two leaves the other one standing.
 */
const MAX_PER_LINE = 50;

const canChange = (item) => props.order.editable && item.mine > 0;
const ownsSomething = computed(() => props.order.items.some(canChange));
const lockedAfterConfirm = computed(() => ['confirmed', 'ready'].includes(props.order.status) && !props.order.is_paid);

/**
 * The line a change is in flight for. Every stepper is held while one is,
 * so two quick taps do not race each other to the server.
 */
const busyItem = ref(null);

function changeLine(item, quantity) {
    const params = { order: props.order.id, item: item.id };
    const options = {
        preserveScroll: true,
        preserveState: true,
        onStart: () => {
            busyItem.value = item.id;
        },
        onFinish: () => {
            busyItem.value = null;
        },
    };

    if (quantity <= 0) {
        router.delete(route('menu.orders.items.destroy', params), options);
    } else {
        router.patch(route('menu.orders.items.update', params), { quantity }, options);
    }
}

/**
 * The menu for the table this order came from.
 *
 * Customer/Menu.vue reads the table out of the query string, the way the
 * QR code on the table supplies it. Linking to a bare /menu therefore sent a
 * guest who wanted a second round back to a menu with no table attached, and
 * their next order arrived belonging to nobody — with the QR code sitting on
 * the table behind them, already scanned once and not about to be scanned
 * again.
 *
 * The label travels too, so the menu can name the table without a lookup.
 */
const backToMenu = computed(() => {
    if (!props.order.table_id) return route('menu.index');

    return route('menu.index', {
        table_id: props.order.table_id,
        ...(props.order.table_label ? { table_label: props.order.table_label } : {}),
    });
});

/**
 * The kitchen's progress, as something the customer can read at a glance
 * rather than a status word they have to interpret. 'cancelled' is
 * deliberately absent — it is not a step along the way, and rendering it as
 * one would imply the food is still coming.
 */
const STEPS = ['pending', 'confirmed', 'ready'];
const currentStep = computed(() => STEPS.indexOf(props.order.status));

/**
 * Everyone at the table orders from their own phone onto the same ticket, so
 * this screen has to show items the person holding it never added. Polling,
 * not websockets: the live channel is private and a customer has no login to
 * authorise against it, and a ticket that updates a few seconds late is
 * perfectly fine — nobody is waiting on the screen, they are waiting on food.
 *
 * What that costs is the thing to watch. This screen is open on every phone
 * at every occupied table at once, and each tick is a full Laravel request
 * against a server that answers one at a time — `php -S` takes no second
 * worker on Windows, where PHP_CLI_SERVER_WORKERS needs a fork() it does not
 * have. A flat 10s tick from thirty phones is three requests a second of
 * permanent background load, competing with the waiter's tablet, the kitchen
 * screen and the receipt the cashier is standing there waiting for. Three
 * rules cut that to a fraction of itself without the customer noticing:
 *
 * 1. A hidden tab does not poll at all. Phones spend most of a sitting in a
 *    pocket or behind another app, and nobody reads a screen they cannot
 *    see. Returning to the tab refreshes immediately, so it shows fresher
 *    data than the old fixed tick ever did.
 * 2. Once the order reaches a state the kitchen no longer moves it out of,
 *    the tick backs off. It does not stop: someone else at the table can
 *    still add a round onto the same ticket, and that has to appear.
 * 3. The active tick is 15s rather than 10s. This status changes when a
 *    human presses a button minutes apart, so five seconds of extra latency
 *    is invisible against it — and it is a third of the requests.
 */
const POLL_ACTIVE_MS = 15000;
const POLL_SETTLED_MS = 60000;
const MOVING_STATUSES = ['pending', 'confirmed'];

let timer = null;

/**
 * The status the running timer's interval was chosen for, so an order moving
 * from 'confirmed' to 'ready' re-arms at the slower rate instead of staying
 * on the fast one for the rest of the sitting. Null whenever no timer runs.
 */
let pollingForStatus = null;

function refresh() {
    // A tick landing mid-change would cancel the guest's own request — the
    // change's response brings the fresh order back anyway.
    if (busyItem.value !== null) return;

    router.reload({ only: ['order'], preserveScroll: true, preserveState: true });
}

function stopPolling() {
    if (timer !== null) {
        clearInterval(timer);
        timer = null;
    }

    pollingForStatus = null;
}

function syncPolling() {
    if (document.hidden) {
        stopPolling();

        return;
    }

    // Already ticking at the rate this status calls for.
    if (timer !== null && pollingForStatus === props.order.status) {
        return;
    }

    stopPolling();
    pollingForStatus = props.order.status;
    timer = setInterval(
        refresh,
        MOVING_STATUSES.includes(props.order.status) ? POLL_ACTIVE_MS : POLL_SETTLED_MS,
    );
}

function onVisibilityChange() {
    if (document.hidden) {
        stopPolling();

        return;
    }

    // Whatever happened while the tab was away is shown now, not one tick
    // from now — this is the moment the customer is actually looking at it.
    refresh();
    syncPolling();
}

watch(() => props.order.status, syncPolling);

onMounted(() => {
    document.addEventListener('visibilitychange', onVisibilityChange);
    syncPolling();
});

onBeforeUnmount(() => {
    document.removeEventListener('visibilitychange', onVisibilityChange);
    stopPolling();
});
</script>

<template>
    <Head :title="order.reference" />

    <div class="mx-auto min-h-screen max-w-md bg-gray-50 px-4 pb-24 pt-8 dark:bg-gray-950">
        <!-- Header: what was ordered, for which table -->
        <div class="rounded-2xl border border-gray-200 bg-white p-6 text-center dark:border-gray-800 dark:bg-gray-900">
            <div
                class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full text-white"
                style="background-color: var(--brand-primary)"
            >
                <NavIcon name="check" class="h-6 w-6" />
            </div>

            <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ t('menu.order_placed') }}</h1>

            <div class="mt-2 flex items-center justify-center gap-2 text-sm text-gray-500">
                <span class="font-mono">{{ order.reference }}</span>
                <span v-if="order.table_label" class="text-gray-300">•</span>
                <span v-if="order.table_label" class="font-medium text-gray-700 dark:text-gray-300">
                    {{ t('menu.table') }} {{ order.table_label }}
                </span>
            </div>

            <span
                class="mt-3 inline-flex rounded-full px-3 py-1 text-xs font-medium"
                :class="statusBadgeClasses(order.status_color)"
            >
                {{ t(`orders.statuses.${order.status}`) }}
            </span>
        </div>

        <!-- Progress: four dots joined by a bar that fills as the kitchen works -->
        <div v-if="currentStep >= 0" class="mt-4 rounded-2xl border border-gray-200 bg-white px-5 py-4 dark:border-gray-800 dark:bg-gray-900">
            <div class="relative flex items-center justify-between">
                <div class="absolute inset-x-0 top-2 h-0.5 bg-gray-200 dark:bg-gray-800"></div>
                <div
                    class="absolute top-2 h-0.5 transition-all duration-500 rtl:right-0 ltr:left-0"
                    :style="{
                        width: `${(currentStep / (STEPS.length - 1)) * 100}%`,
                        backgroundColor: 'var(--brand-primary)',
                    }"
                ></div>

                <div v-for="(step, index) in STEPS" :key="step" class="relative flex flex-col items-center gap-1.5">
                    <span
                        class="h-4 w-4 rounded-full border-2 transition-colors"
                        :style="index <= currentStep
                            ? { backgroundColor: 'var(--brand-primary)', borderColor: 'var(--brand-primary)' }
                            : {}"
                        :class="index > currentStep ? 'border-gray-300 bg-white dark:border-gray-700 dark:bg-gray-900' : ''"
                    ></span>
                    <span
                        class="text-[10px] leading-none"
                        :class="index <= currentStep ? 'font-medium text-gray-900 dark:text-gray-100' : 'text-gray-400'"
                    >
                        {{ t(`orders.statuses.${step}`) }}
                    </span>
                </div>
            </div>
        </div>

        <!-- Too late: the waiter confirmed while this guest was changing it. -->
        <div
            v-if="page.props.errors?.order"
            class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300"
        >
            {{ page.props.errors.order }}
        </div>

        <p
            v-else-if="ownsSomething"
            class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-center text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300"
        >
            {{ t('menu.edit_until_confirmed') }}
        </p>

        <!-- The bill -->
        <div class="mt-4 overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3 dark:border-gray-800">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ t('menu.your_order') }}</h2>
                <span class="text-xs text-gray-400">{{ itemCount }}</span>
            </div>

            <div
                v-for="item in order.items"
                :key="item.id"
                class="flex items-start justify-between gap-3 border-b border-gray-100 px-5 py-3 last:border-0 dark:border-gray-800"
            >
                <div class="flex min-w-0 items-start gap-3">
                    <!-- The quantity gets its own chip: on a shared table it is
                         the number most likely to have changed since last look.
                         A line this guest can still change shows it in the
                         stepper instead. -->
                    <span
                        v-if="!canChange(item)"
                        class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-xs font-bold text-white"
                        style="background-color: var(--brand-primary)"
                    >{{ item.quantity }}</span>

                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ item.product_name }}</p>

                        <p v-if="item.modifiers.length" class="mt-0.5 text-xs text-gray-500">
                            {{ item.modifiers.map((m) => m.name).join('، ') }}
                        </p>

                        <p v-if="item.notes" class="mt-0.5 text-xs italic text-gray-400">{{ item.notes }}</p>

                        <p v-if="item.quantity > 1" class="mt-0.5 text-[11px] text-gray-400 tabular-nums">
                            {{ formatPrice(item.unit_price_cents) }} × {{ item.quantity }}
                        </p>
                    </div>
                </div>

                <div class="flex shrink-0 flex-col items-end gap-2">
                    <p class="text-sm font-medium text-gray-800 tabular-nums dark:text-gray-200">
                        {{ formatPrice(item.subtotal_cents) }}
                    </p>

                    <!-- At one, minus becomes the bin: one less of the last
                         one is taking it off. -->
                    <div
                        v-if="canChange(item)"
                        class="flex items-center rounded-lg border border-gray-200 transition-opacity dark:border-gray-700"
                        :class="{ 'opacity-50': busyItem === item.id }"
                    >
                        <button
                            type="button"
                            class="flex h-8 w-8 items-center justify-center text-gray-600 disabled:cursor-not-allowed dark:text-gray-300"
                            :disabled="busyItem !== null"
                            :aria-label="item.mine === 1
                                ? t('menu.remove_item', { name: item.product_name })
                                : t('menu.decrease_item', { name: item.product_name })"
                            @click="changeLine(item, item.mine - 1)"
                        >
                            <NavIcon v-if="item.mine === 1" name="trash" class="h-4 w-4 text-red-500" />
                            <NavIcon v-else name="minus" class="h-4 w-4" />
                        </button>

                        <span class="w-7 text-center text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                            {{ item.quantity }}
                        </span>

                        <button
                            type="button"
                            class="flex h-8 w-8 items-center justify-center text-gray-600 disabled:cursor-not-allowed disabled:opacity-40 dark:text-gray-300"
                            :disabled="busyItem !== null || item.mine >= MAX_PER_LINE"
                            :aria-label="t('menu.increase_item', { name: item.product_name })"
                            @click="changeLine(item, item.mine + 1)"
                        >
                            <NavIcon name="plus" class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-between bg-gray-50 px-5 py-4 dark:bg-gray-950/50">
                <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ t('menu.total') }}</span>
                <span class="text-lg font-bold tabular-nums" style="color: var(--brand-primary)">
                    {{ formatPrice(order.total_cents) }}
                </span>
            </div>

            <div
                v-if="order.is_paid"
                class="flex items-center justify-center gap-1.5 border-t border-green-100 bg-green-50 py-2 text-xs font-medium text-green-700 dark:border-green-900 dark:bg-green-950 dark:text-green-400"
            >
                <NavIcon name="check" class="h-3.5 w-3.5" />
                {{ t('orders.paid') }}
            </div>
        </div>

        <p class="mt-3 text-center text-xs text-gray-400">{{ t('menu.shared_table_hint') }}</p>

        <p v-if="lockedAfterConfirm" class="mt-1 text-center text-xs text-gray-400">{{ t('menu.locked_after_confirm') }}</p>

        <!-- Back to the menu this guest is actually sitting at.
             The QR code carries the table, and it is on the table — not in
             the guest's hand — so a link that drops it leaves them ordering
             a second round from a menu that belongs to no table at all. -->
        <Link
            :href="backToMenu"
            class="mt-5 block rounded-xl border border-gray-200 bg-white py-3 text-center text-sm font-medium text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
        >
            {{ t('menu.back_to_menu') }}
        </Link>
    </div>
</template>
