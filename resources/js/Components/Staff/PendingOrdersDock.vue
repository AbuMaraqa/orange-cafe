<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Link, usePage } from '@inertiajs/vue3';
import NavIcon from '@/Components/NavIcon.vue';
import { usePendingOrders } from '@/composables/usePendingOrders';

/**
 * The standing "somebody is still waiting" marker, bottom-left of every
 * staff screen.
 *
 * Sits in the corner rather than in the flow because it has to be present
 * on screens that are about something else entirely — the new-order form,
 * the till, the kitchen list. A waiter halfway through table 4's order
 * should be able to notice table 9 without leaving what they are doing, and
 * without an alert card covering the form they are typing into.
 *
 * Collapsed it is a count. Tapped it lists the tables, oldest at the bottom
 * so the longest wait is the one nearest the thumb. It cannot be dismissed:
 * it goes away when the orders do, which is the only honest thing for it to
 * do.
 *
 * @see usePendingOrders — the list itself, and how it stays current.
 */
const { t } = useI18n();
const page = usePage();
const { orders } = usePendingOrders();

const open = ref(false);

/** Ticks so "3 minutes ago" does not sit there saying "just now" all night. */
const now = ref(Date.now());
let clock = null;

const count = computed(() => orders.length);

// Where "deal with it" lives for whoever is looking: a waiter's floor plan,
// a cashier's till. Shared by HandleInertiaRequests precisely because
// hardcoding the waiter screen answers a cashier with a 403.
const homeRoute = computed(() => page.props.auth?.home_route ?? null);

function minutesSince(iso) {
    if (!iso) return 0;

    return Math.max(0, Math.floor((now.value - new Date(iso).getTime()) / 60000));
}

function waitLabel(iso) {
    const minutes = minutesSince(iso);

    return minutes < 1 ? t('pending_dock.just_now') : t('pending_dock.minutes_ago', { count: minutes });
}

// A new one arriving while the panel is shut should be visible as movement,
// not just a number that quietly changes by one.
const pulsing = ref(false);
let pulseTimer = null;

watch(count, (next, previous) => {
    if (next > previous) {
        pulsing.value = true;
        clearTimeout(pulseTimer);
        pulseTimer = setTimeout(() => (pulsing.value = false), 2000);
    }

    // Nothing left to show, so nothing left to keep open.
    if (next === 0) open.value = false;
});

function onKeydown(event) {
    if (event.key === 'Escape' && open.value) open.value = false;
}

onMounted(() => {
    clock = setInterval(() => (now.value = Date.now()), 30000);
    window.addEventListener('keydown', onKeydown);
});

onUnmounted(() => {
    clearInterval(clock);
    clearTimeout(pulseTimer);
    window.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <!-- Bottom-left in both directions on purpose. This is furniture, not
         text: staff learn where it lives, and a marker that swaps corners
         when somebody changes the interface language is a marker they have
         to find again. z-40 keeps it under the alert card and the modals,
         which are the things that genuinely want the whole screen. -->
    <div v-if="count > 0" class="fixed bottom-4 left-4 z-40 flex flex-col items-start gap-2 print:hidden">
        <Transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="translate-y-2 opacity-0"
            leave-active-class="transition duration-100 ease-in"
            leave-to-class="translate-y-2 opacity-0"
        >
            <div
                v-if="open"
                class="w-72 max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl border border-amber-200 bg-white shadow-2xl dark:border-amber-900 dark:bg-gray-900"
            >
                <p class="border-b border-amber-100 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900 dark:border-amber-900/60 dark:bg-amber-950 dark:text-amber-100">
                    {{ t('pending_dock.title') }}
                </p>

                <ul class="max-h-64 divide-y divide-gray-100 overflow-y-auto dark:divide-gray-800">
                    <li v-for="order in orders" :key="order.id" class="flex items-center justify-between gap-3 px-3 py-2">
                        <span class="flex items-center gap-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                            <span class="inline-flex h-7 min-w-7 items-center justify-center rounded-lg bg-amber-500 px-1.5 text-xs font-bold text-white">
                                {{ order.table_label ?? '—' }}
                            </span>
                            {{ order.table_label ? t('pending_dock.table', { table: order.table_label }) : t('pending_dock.no_table') }}
                        </span>
                        <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ waitLabel(order.created_at) }}</span>
                    </li>
                </ul>

                <Link
                    v-if="homeRoute"
                    :href="route(homeRoute)"
                    class="block border-t border-gray-100 px-3 py-2 text-center text-sm font-semibold text-amber-700 transition hover:bg-amber-50 dark:border-gray-800 dark:text-amber-300 dark:hover:bg-amber-950"
                    @click="open = false"
                >
                    {{ t('pending_dock.open') }}
                </Link>
            </div>
        </Transition>

        <button
            type="button"
            class="flex items-center gap-2 rounded-full bg-amber-500 py-2 pe-4 ps-3 text-white shadow-lg transition hover:bg-amber-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-2 dark:ring-offset-gray-900"
            :class="pulsing ? 'animate-pulse' : ''"
            :aria-expanded="open"
            :aria-label="open ? t('pending_dock.hide') : t('pending_dock.show')"
            @click="open = !open"
        >
            <NavIcon name="bell" class="h-5 w-5" />
            <span class="text-sm font-bold tabular-nums">{{ count }}</span>
            <span class="hidden text-sm font-medium sm:inline">{{ t('pending_dock.count', { count }) }}</span>
        </button>
    </div>
</template>
