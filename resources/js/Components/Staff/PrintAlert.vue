<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';

/**
 * The cashier's side of a failed print.
 *
 * Deliberately almost content-free: a count, which printer, and two
 * buttons. A cashier is standing in front of a customer and must not be
 * shown a socket error, an attempt count or the word "queue" — those are on
 * the printers screen, for whoever is responsible for fixing it.
 *
 * Polled rather than pushed. The app has Reverb and could broadcast this,
 * but a websocket that has dropped fails silently and the one alert you
 * cannot afford to miss is the one telling you the kitchen never saw the
 * order. A poll that fails simply tries again in thirty seconds.
 *
 * ## Dismissing actually dismisses
 *
 * It used to be impossible to get rid of this panel, in two compounding
 * ways. `dismissed` was component state, so every Inertia navigation
 * remounted the banner and brought it straight back; and the poll cleared
 * the flag whenever `count` rose, which on a fresh mount it always did —
 * the local count starts at zero, so the first response after any
 * navigation looked like a brand new failure.
 *
 * The result was a warning the cashier could not clear about a printer they
 * already knew was broken, sitting across the top of the till all evening.
 * A warning that cannot be acknowledged stops being read, which costs you
 * the next one too.
 *
 * So dismissal is now per print job and it persists. Waving the panel away
 * acknowledges exactly the jobs listed in it; those stay hidden across
 * reloads and navigations. A job the cashier has not seen — a different
 * order, a different printer, the same printer failing again later — is a
 * new failure and shows up regardless.
 */
const props = defineProps({
    /** Where the "view details" button goes; hidden for staff without access. */
    detailsRoute: { type: String, default: null },
    intervalMs: { type: Number, default: 30000 },
});

const STORAGE_KEY = 'orange.print-alerts.dismissed';

const jobs = ref([]);

/** Print job ids this till has already been shown and told to forget. */
const dismissedIds = ref(new Set());

function loadDismissed() {
    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);

        dismissedIds.value = new Set(raw ? JSON.parse(raw) : []);
    } catch {
        // Private mode, cleared storage, a browser that refuses: the panel
        // simply behaves as though nothing was dismissed, which is the safe
        // direction to fail in.
        dismissedIds.value = new Set();
    }
}

function saveDismissed() {
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify([...dismissedIds.value]));
    } catch {
        // Not worth taking the alert down for — it just will not persist.
    }
}

/** What the cashier has not already waved away. */
const visibleJobs = computed(() => jobs.value.filter((job) => !dismissedIds.value.has(job.id)));

const count = computed(() => visibleJobs.value.length);

let timer = null;

async function poll() {
    try {
        const response = await fetch(route('staff.print-alerts'), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) return;

        const data = await response.json();

        jobs.value = data.jobs ?? [];

        // Forget ids that are no longer failing — the job was retried,
        // printed, or cleared. Without this the list grows for the life of
        // the browser profile, and an id that came back around could be
        // silently suppressed.
        const live = new Set(jobs.value.map((job) => job.id));
        const kept = [...dismissedIds.value].filter((id) => live.has(id));

        if (kept.length !== dismissedIds.value.size) {
            dismissedIds.value = new Set(kept);
            saveDismissed();
        }
    } catch {
        // Offline or mid-deploy. The next tick tries again; a failed poll
        // must never take the till screen down with it.
    }
}

function dismiss() {
    // Only what is on screen right now. Anything that fails after this tap
    // is news the cashier has not had yet.
    const next = new Set(dismissedIds.value);

    visibleJobs.value.forEach((job) => next.add(job.id));

    dismissedIds.value = next;
    saveDismissed();
}

onMounted(() => {
    loadDismissed();
    poll();
    timer = setInterval(poll, props.intervalMs);
});

onUnmounted(() => clearInterval(timer));
</script>

<template>
    <div v-if="count > 0"
         class="mb-4 rounded-xl border-2 border-amber-300 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/40">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="flex items-center gap-2 font-semibold text-amber-900 dark:text-amber-200">
                    <span class="text-lg">⚠️</span>
                    {{ $t('printers.alert_title') }}
                </p>

                <!-- One line per affected printer, phrased the way the cafe
                     asked: which printer, and how many items are missing. -->
                <ul class="mt-2 space-y-1 text-sm text-amber-800 dark:text-amber-300">
                    <li v-for="job in visibleJobs" :key="job.id">
                        {{ $t('printers.alert_line', {
                            printer: job.printer_name,
                            count: job.items_count,
                            order: job.order_reference,
                        }) }}
                    </li>
                </ul>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <Link v-if="detailsRoute" :href="detailsRoute"
                      class="rounded-lg bg-amber-600 px-3 py-2 text-sm font-semibold text-white transition hover:bg-amber-700">
                    {{ $t('printers.view_details') }}
                </Link>
                <button type="button"
                        class="rounded-lg border border-amber-300 px-3 py-2 text-sm font-medium text-amber-800 transition hover:bg-amber-100 dark:border-amber-700 dark:text-amber-300"
                        @click="dismiss">
                    {{ $t('common.close') }}
                </button>
            </div>
        </div>
    </div>
</template>
