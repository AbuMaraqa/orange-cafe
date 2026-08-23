<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatDateTime } from '@/composables/useMoney';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * إدارة الطابعات — the technical screen.
 *
 * Everything a cashier must never be shown lives here: the error strings,
 * the attempt counts, which sections feed which printer. The till gets a
 * traffic light and nothing else.
 */
const props = defineProps({
    printers: { type: Array, required: true },
    unprintedTotal: { type: Number, default: 0 },
    /** Stations the menu routes to that have no active printer. */
    uncoveredStations: { type: Array, default: () => [] },
});

const { t } = useI18n();

/**
 * Finding the printers so nobody has to type an IP address.
 *
 * A cafe installing this on a new machine cannot answer "what is the
 * printer's IP" — it is not written on the printer and the router changes
 * it. That single question is enough to end an install with the software
 * working and no printer configured, which is exactly what happened on the
 * second machine.
 */
const scanning = ref(false);
const scanned = ref(null);

async function discover() {
    scanning.value = true;
    scanned.value = null;

    try {
        const response = await fetch(route('admin.printers.discover'), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        scanned.value = response.ok ? (await response.json()).printers : [];
    } catch {
        // Offline, or the sweep was interrupted. An empty list reads the
        // same as "found nothing", which is the honest outcome either way.
        scanned.value = [];
    } finally {
        scanning.value = false;
    }
}

/** Carries the address into the add form so it is never retyped. */
function addDiscovered(found) {
    router.get(route('admin.printers.create'), { ip_address: found.ip, port: found.port });
}

function destroy(printer) {
    if (confirm(`${printer.name}: ` + t('common.are_you_sure'))) {
        router.delete(route('admin.printers.destroy', printer.id), { preserveScroll: true });
    }
}

/** Opens the socket and closes it — proves reachable, proves nothing else. */
function test(printer) {
    router.post(route('admin.printers.test', printer.id), {}, { preserveScroll: true });
}

/** Puts a real page through: paper, head, spooler and encoding. */
function testPrint(printer) {
    router.post(route('admin.printers.test-print', printer.id), {}, { preserveScroll: true });
}

const statusClasses = {
    online: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400',
    checking: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400',
    offline: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400',
    unresponsive: 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-400',
    unknown: 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400',
};

const statusDots = {
    online: '🟢',
    checking: '🟡',
    offline: '🔴',
    unresponsive: '⚠️',
    unknown: '⚪',
};

// The till printer first, then the kitchens: it is the one whose failure
// the cashier feels immediately.
const cashierPrinters = computed(() => props.printers.filter((printer) => printer.is_cashier));
const kitchenPrinters = computed(() => props.printers.filter((printer) => !printer.is_cashier));

function checkedAgo(iso) {
    if (!iso) return null;

    const minutes = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000));

    return minutes < 1 ? t('staff.just_now') : t('staff.minutes_ago', { minutes });
}
</script>

<template>
    <Head :title="$t('nav.printers')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('nav.printers') }}</template>

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <Link :href="route('admin.print-jobs.index')"
                  class="inline-flex items-center gap-2 text-sm font-medium"
                  :class="unprintedTotal > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-500'">
                <span v-if="unprintedTotal > 0">⚠️</span>
                {{ $t('printers.jobs_title') }}
                <span v-if="unprintedTotal > 0"
                      class="rounded-full bg-amber-100 px-2 py-0.5 text-xs dark:bg-amber-900/40">{{ unprintedTotal }}</span>
            </Link>

            <div class="flex items-center gap-2">
                <button type="button"
                        class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 disabled:opacity-50 dark:border-gray-600 dark:text-gray-200"
                        :disabled="scanning"
                        @click="discover">
                    {{ scanning ? $t('printers.discovering') : $t('printers.discover') }}
                </button>

                <Link :href="route('admin.printers.create')">
                    <PrimaryButton>{{ $t('common.add') }}</PrimaryButton>
                </Link>
            </div>
        </div>

        <!-- Items routed to a station with no printer are cooked by nobody,
             and every screen in the app shows that order as normal. This is
             the only place it is visible before the food is missed. -->
        <div v-if="uncoveredStations.length"
             class="mb-4 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200">
            <p class="font-semibold">{{ $t('printers.uncovered_title') }}</p>
            <p class="mt-1">{{ $t('printers.uncovered_body', { stations: uncoveredStations.join('، ') }) }}</p>
        </div>

        <!-- What the sweep found. -->
        <div v-if="scanned !== null"
             class="mb-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p v-if="scanned.length === 0" class="text-sm text-gray-500">{{ $t('printers.discover_none') }}</p>

            <template v-else>
                <p class="mb-2 text-sm font-semibold text-gray-900 dark:text-gray-100">
                    {{ $t('printers.discover_found', { count: scanned.length }) }}
                </p>
                <div class="space-y-2">
                    <div v-for="found in scanned" :key="found.ip"
                         class="flex items-center justify-between gap-3 rounded-md border border-gray-200 px-3 py-2 dark:border-gray-700">
                        <span class="flex min-w-0 items-center gap-2">
                            <span class="font-mono text-sm text-gray-700 dark:text-gray-200" dir="ltr">{{ found.ip }}:{{ found.port }}</span>
                            <span v-if="found.vendor"
                                  class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ found.vendor }}</span>
                            <!-- These printers take one connection at a time,
                                 so "busy" here usually means this app is
                                 talking to it — not that it is broken. -->
                            <span v-if="!found.reachable" class="text-xs text-amber-600 dark:text-amber-400">{{ $t('printers.discover_busy') }}</span>
                        </span>

                        <span v-if="found.already_added" class="shrink-0 text-xs text-gray-400">
                            {{ $t('printers.discover_already', { name: found.name }) }}
                        </span>
                        <button v-else type="button"
                                class="rounded-md px-3 py-1 text-sm font-medium text-white"
                                :style="{ backgroundColor: 'var(--brand-primary)' }"
                                @click="addDiscovered(found)">
                            {{ $t('common.add') }}
                        </button>
                    </div>
                </div>
            </template>
        </div>

        <template v-for="group in [
            { title: $t('printers.cashier_group'), rows: cashierPrinters },
            { title: $t('printers.kitchen_group'), rows: kitchenPrinters },
        ]" :key="group.title">
            <div v-if="group.rows.length" class="mb-6">
                <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ group.title }}
                </h3>

                <div class="grid gap-3 lg:grid-cols-2">
                    <div v-for="printer in group.rows" :key="printer.id"
                         class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span>{{ statusDots[printer.status] ?? statusDots.unknown }}</span>
                                    <span class="truncate font-semibold text-gray-900 dark:text-gray-100">{{ printer.name }}</span>
                                    <span v-if="!printer.is_active"
                                          class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500 dark:bg-gray-800">
                                        {{ $t('common.inactive') }}
                                    </span>
                                </div>
                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    {{ printer.station }} ·
                                    <span dir="ltr">
                                        {{ printer.connection_type === 'network'
                                            ? `${printer.ip_address}:${printer.port}`
                                            : (printer.connection_type === 'windows' ? printer.windows_share : 'USB') }}
                                    </span>
                                </div>
                            </div>

                            <span class="shrink-0 rounded-full px-2 py-1 text-xs font-medium"
                                  :class="statusClasses[printer.status] ?? statusClasses.unknown">
                                {{ $t(`printers.status.${printer.status ?? 'unknown'}`) }}
                            </span>
                        </div>

                        <!-- Which sections feed this printer: the routing
                             rule صنف → فئة → محطة → طابعة, read backwards. -->
                        <div v-if="printer.categories.length" class="mt-3 flex flex-wrap gap-1">
                            <span v-for="name in printer.categories" :key="name"
                                  class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                {{ name }}
                            </span>
                        </div>
                        <p v-else-if="!printer.is_cashier" class="mt-3 text-xs text-amber-600 dark:text-amber-400">
                            {{ $t('printers.no_categories') }}
                        </p>

                        <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                            <dt class="text-gray-500">{{ $t('printers.last_seen') }}</dt>
                            <dd class="text-gray-700 dark:text-gray-300">{{ checkedAgo(printer.status_checked_at) ?? '—' }}</dd>

                            <template v-if="printer.offline_since">
                                <dt class="text-gray-500">{{ $t('printers.offline_since') }}</dt>
                                <dd class="text-red-600 dark:text-red-400">{{ formatDateTime(printer.offline_since) }}</dd>
                            </template>

                            <template v-if="printer.recovered_at">
                                <dt class="text-gray-500">{{ $t('printers.recovered_at') }}</dt>
                                <dd class="text-green-600 dark:text-green-400">{{ formatDateTime(printer.recovered_at) }}</dd>
                            </template>

                            <template v-if="printer.unprinted_count">
                                <dt class="text-gray-500">{{ $t('printers.unprinted') }}</dt>
                                <dd>
                                    <Link :href="route('admin.print-jobs.index', { printer_id: printer.id })"
                                          class="font-medium text-amber-600 dark:text-amber-400">
                                        {{ printer.unprinted_count }}
                                    </Link>
                                </dd>
                            </template>
                        </dl>

                        <p v-if="printer.last_error"
                           class="mt-2 rounded bg-red-50 p-2 font-mono text-xs text-red-700 dark:bg-red-950/30 dark:text-red-400">
                            {{ printer.last_error }}
                        </p>

                        <div class="mt-3 flex flex-wrap items-center gap-3 border-t border-gray-100 pt-3 text-sm dark:border-gray-800">
                            <button type="button" class="text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                                    @click="test(printer)">{{ $t('printers.test_connection') }}</button>
                            <button type="button" class="font-medium" style="color: var(--brand-primary)"
                                    @click="testPrint(printer)">🖨 {{ $t('printers.test_print') }}</button>
                            <Link :href="route('admin.printers.edit', printer.id)"
                                  class="text-gray-500 hover:text-gray-800 dark:hover:text-gray-200">{{ $t('common.edit') }}</Link>
                            <button type="button" class="text-red-600 hover:text-red-800"
                                    @click="destroy(printer)">{{ $t('common.delete') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <div v-if="printers.length === 0"
             class="rounded-lg border border-gray-200 bg-white p-10 text-center text-sm text-gray-400 dark:border-gray-800 dark:bg-gray-900">
            —
        </div>
    </AuthenticatedLayout>
</template>
