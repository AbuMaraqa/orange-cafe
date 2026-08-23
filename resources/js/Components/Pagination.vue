<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { router, usePage } from '@inertiajs/vue3';

/**
 * The pagination control, for every table in the app.
 *
 * One component rather than one per screen, because the thing staff learn
 * is the position and behaviour, not the markup: the page picker is in the
 * same corner and the page-size selector does the same thing on the print
 * queue as it does on the expenses list.
 *
 * ## It navigates by URL, not by event
 *
 * Changing a page is an Inertia visit that keeps every other query
 * parameter as it stands. That is what makes filters, search and sort
 * survive a page change without any screen having to remember to pass them
 * along — the URL is already the state, and this only ever edits one key of
 * it. It also means a paginated view is linkable and survives a refresh,
 * which a purely in-memory page number does not.
 *
 * `preserveState` and `preserveScroll` keep the surrounding screen still:
 * an open filter panel stays open and the list does not jump to the top,
 * which on a tablet is the difference between paging through a list and
 * losing your place in it.
 *
 * ## Sizes are touch-first
 *
 * Every target is at least 40px. These screens are used with a thumb on a
 * tablet propped next to a till, often by someone holding something else.
 *
 * @see App\Http\Controllers\Concerns\PresentsPagination for the payload.
 */
const props = defineProps({
    /**
     * @type {{current_page:number, last_page:number, per_page:number,
     *         total:number, from:?number, to:?number, page_sizes:number[]}}
     */
    meta: { type: Object, required: true },

    /**
     * Extra query parameters to carry across a page change.
     *
     * Rarely needed: the current URL's parameters are preserved already.
     * It exists for a screen whose filter state lives somewhere other than
     * the address bar.
     */
    extraQuery: { type: Object, default: () => ({}) },
});

const { t } = useI18n();
const page = usePage();

const isRtl = computed(() => page.props.locale?.direction === 'rtl');

// `meta` is required, so these read it directly. The page sizes come
// with the payload rather than being restated here — a second copy of
// that policy in JS is the thing PresentsPagination exists to prevent.
const currentPage = computed(() => props.meta.current_page);
const lastPage = computed(() => props.meta.last_page);
const total = computed(() => props.meta.total);
const pageSizes = computed(() => props.meta.page_sizes);

// Nothing to page through and nothing to say about it. A control that
// renders "1–0 of 0" under an empty table is noise on top of a message the
// table has already delivered.
const isVisible = computed(() => total.value > 0);

// Only worth drawing the page machinery when there is more than one page;
// the range and the size picker still earn their place on a single page.
const hasPages = computed(() => lastPage.value > 1);

const rangeLabel = computed(() => {
    if (total.value === 1) return t('pagination.showing_one');

    return t('pagination.showing', {
        from: props.meta.from,
        to: props.meta.to,
        total: total.value,
    });
});

/**
 * The page numbers worth drawing: the ends, the neighbours, and a gap.
 *
 * A café's print queue can run to dozens of pages, and a control that draws
 * every number stops being usable at about fifteen — it wraps onto three
 * lines on a tablet and the number you want is somewhere in the middle of
 * them. `null` marks an elision the template renders as a gap.
 */
const pageNumbers = computed(() => {
    const last = lastPage.value;
    const current = currentPage.value;

    if (last <= 7) {
        return Array.from({ length: last }, (_, i) => i + 1);
    }

    const pages = new Set([1, last, current]);

    if (current - 1 > 1) pages.add(current - 1);
    if (current + 1 < last) pages.add(current + 1);

    const sorted = [...pages].sort((a, b) => a - b);
    const withGaps = [];

    sorted.forEach((number, index) => {
        if (index > 0 && number - sorted[index - 1] > 1) withGaps.push(null);
        withGaps.push(number);
    });

    return withGaps;
});

function visit(query) {
    router.get(
        window.location.pathname,
        { ...currentQuery(), ...props.extraQuery, ...query },
        { preserveState: true, preserveScroll: true, replace: true }
    );
}

/** Whatever is already in the address bar — filters, search, sort. */
function currentQuery() {
    return Object.fromEntries(new URLSearchParams(window.location.search).entries());
}

function goToPage(number) {
    if (number === currentPage.value || number < 1 || number > lastPage.value) return;

    visit({ page: number });
}

/**
 * Changing the page size always returns to page one.
 *
 * Staying put would be arithmetic nobody asked for: page 8 of 40 rows is
 * somewhere around page 3 of 100, and landing on a page that no longer
 * exists is worse. Page one is the only answer that is always correct and
 * always what was meant.
 */
function changePageSize(event) {
    visit({ per_page: Number(event.target.value), page: 1 });
}
</script>

<template>
    <div
        v-if="isVisible"
        class="flex flex-col gap-3 border-t border-gray-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800"
    >
        <!-- The count first: on a narrow screen it is the line that answers
             "did my filter work?", and it should not be below the fold. -->
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ rangeLabel }}</p>

        <div class="flex flex-wrap items-center gap-3">
            <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                <select
                    class="h-10 rounded-lg border-gray-300 py-0 text-sm focus:border-gray-400 focus:ring-0 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                    :value="meta.per_page"
                    @change="changePageSize"
                >
                    <option v-for="size in pageSizes" :key="size" :value="size">{{ size }}</option>
                </select>
                {{ t('pagination.per_page') }}
            </label>

            <nav v-if="hasPages" class="flex items-center gap-1" :aria-label="t('pagination.page_of', { page: currentPage, last: lastPage })">
                <button
                    type="button"
                    class="flex h-10 w-10 items-center justify-center rounded-lg border border-gray-300 text-gray-600 transition enabled:hover:bg-gray-50 disabled:opacity-40 dark:border-gray-700 dark:text-gray-300 dark:enabled:hover:bg-gray-800"
                    :disabled="currentPage <= 1"
                    :aria-label="t('pagination.previous')"
                    @click="goToPage(currentPage - 1)"
                >
                    <!-- Points the way the reader is going, not the way the
                         DOM is written: in Arabic "previous" is to the right. -->
                    <svg class="h-4 w-4" :class="isRtl ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>

                <template v-for="(number, index) in pageNumbers" :key="`${number}-${index}`">
                    <span v-if="number === null" class="px-1 text-gray-400" aria-hidden="true">…</span>
                    <button
                        v-else
                        type="button"
                        class="h-10 min-w-10 rounded-lg border px-3 text-sm font-medium transition"
                        :class="number === currentPage
                            ? 'border-transparent bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900'
                            : 'border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800'"
                        :aria-current="number === currentPage ? 'page' : undefined"
                        :aria-label="t('pagination.go_to_page', { page: number })"
                        @click="goToPage(number)"
                    >
                        {{ number }}
                    </button>
                </template>

                <button
                    type="button"
                    class="flex h-10 w-10 items-center justify-center rounded-lg border border-gray-300 text-gray-600 transition enabled:hover:bg-gray-50 disabled:opacity-40 dark:border-gray-700 dark:text-gray-300 dark:enabled:hover:bg-gray-800"
                    :disabled="currentPage >= lastPage"
                    :aria-label="t('pagination.next')"
                    @click="goToPage(currentPage + 1)"
                >
                    <svg class="h-4 w-4" :class="isRtl ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                    </svg>
                </button>
            </nav>
        </div>
    </div>
</template>
