<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import NavIcon from '@/Components/NavIcon.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import ToggleSwitch from '@/Components/ToggleSwitch.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import draggable from 'vuedraggable';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    products: {
        type: Array,
        required: true,
    },
    languages: {
        type: Array,
        required: true,
    },
    filters: {
        type: Object,
        required: true,
    },
    pagination: {
        type: Object,
        required: true,
    },
    filterOptions: {
        type: Object,
        required: true,
    },
    totalCount: {
        type: Number,
        required: true,
    },
});

const { t } = useI18n();
const page = usePage();

// Only the search box keeps local state, and only because it is debounced —
// every other control reads straight from props, so the bar always shows what
// the server actually filtered by (including filters arriving in a shared or
// bookmarked URL, which is the whole reason they live in the query string).
const search = ref(props.filters.search ?? '');
let searchTimer = null;

function reload(overrides = {}) {
    const pick = (key, fallback) => (key in overrides ? overrides[key] : fallback);

    const params = {
        search: pick('search', search.value),
        menu_id: pick('menu_id', props.filters.menu_id),
        category_id: pick('category_id', props.filters.category_id),
        tag_id: pick('tag_id', props.filters.tag_id),
        station: pick('station', props.filters.station),
        status: pick('status', props.filters.status),
        placement: pick('placement', props.filters.placement),
        translations: pick('translations', props.filters.translations),
    };

    // Empty values are dropped rather than sent as blanks, so "no filter" is
    // a clean URL worth bookmarking and the back button has something to
    // return to.
    for (const key of Object.keys(params)) {
        if (params[key] === null || params[key] === '' || params[key] === undefined) {
            delete params[key];
        }
    }

    // A new filter is a new list: page 7 of the old one is meaningless and
    // often does not exist. The page size rides along because it is a
    // preference about how the admin likes to read, not part of the query.
    params.per_page = props.pagination?.per_page ?? 25;
    delete params.page;

    router.get(route('admin.products.index'), params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

// One request per pause, not one per keystroke — the query hits three tables.
function onSearchInput() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(reload, 300);
}

const hasFilters = computed(
    () => Boolean(search.value) || Object.entries(props.filters).some(([key, value]) => key !== 'search' && value),
);

function clearFilters() {
    clearTimeout(searchTimer);
    search.value = '';

    router.get(route('admin.products.index'), {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

// Rows kept locally because draggable mutates the array it is bound to.
const rows = ref([...props.products]);

watch(() => props.products, (products) => { rows.value = [...products]; });

// Dragging is off while any filter is on, and this is not a nicety.
// sort_order is a single sequence over the whole catalog, so numbering a
// filtered subset from zero would move those products past everything the
// filter is hiding — reordering three juices inside a search for "عصير"
// would silently send all three to the top of the menu. The server refuses
// a partial list too (ProductController::reorder); this is what stops the
// admin from trying in the first place.
// Paging splits the list the same way filtering does, and for the same
// reason it cannot be reordered: dragging row 3 above row 1 on page two is
// a statement about three of a hundred and forty-eight products, and the
// server rightly refuses a partial list (ProductController::reorder). The
// handle is hidden rather than left to fail, so nobody drags a row and
// waits for an error to explain why nothing moved.
const canReorder = computed(
    () => !hasFilters.value && (props.pagination?.last_page ?? 1) <= 1 && rows.value.length > 1
);

// Which of the two reasons it is off, so the tooltip says something true.
const reorderBlockedBy = computed(() => (hasFilters.value ? 'filter' : 'paging'));


function persistOrder() {
    router.patch(
        route('admin.products.reorder'),
        { order: rows.value.map((product) => product.id) },
        { preserveScroll: true },
    );
}

// The switch flips before the request lands, because the reason to use it
// at all is speed — the kitchen has just run out of something and the row
// has to go grey now. The server's answer overwrites this on the way back
// (and puts it back if the save failed), so a rejected toggle cannot leave
// the table claiming something the database does not agree with.
const activeStates = reactive({});
const savingIds = ref([]);

function seedActiveStates(products) {
    for (const product of products) {
        activeStates[product.id] = product.is_active;
    }
}

seedActiveStates(props.products);

watch(() => props.products, seedActiveStates, { deep: true });

function toggleActive(product) {
    const previous = activeStates[product.id];

    activeStates[product.id] = !previous;
    savingIds.value = [...savingIds.value, product.id];

    router.patch(route('admin.products.toggle-active', product.id), {}, {
        preserveScroll: true,
        onError: () => { activeStates[product.id] = previous; },
        onFinish: () => { savingIds.value = savingIds.value.filter((id) => id !== product.id); },
    });
}

function destroy(product) {
    if (confirm(`${product.name}: ` + t('common.are_you_sure'))) {
        router.delete(route('admin.products.destroy', product.id), { preserveScroll: true });
    }
}

function languageName(code, languages) {
    return languages.find((l) => l.code === code)?.native_name ?? code;
}

function formatPrice(cents) {
    return (cents / 100).toFixed(2);
}
</script>

<template>
    <Head :title="$t('admin.products.title')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('admin.products.title') }}</template>

        <div class="mb-4 flex justify-end gap-3">
            <!-- A plain anchor, not an Inertia <Link>: the response is a PDF,
                 and Inertia would try to parse it as a page and throw. target
                 keeps the product list where it is — this is something an
                 owner glances at and sends on, not somewhere they navigate. -->
            <a :href="route('admin.menu.pdf')" target="_blank" rel="noopener">
                <SecondaryButton>{{ $t('admin.products.menu_pdf') }}</SecondaryButton>
            </a>
            <Link :href="route('admin.products.create')">
                <PrimaryButton>{{ $t('admin.products.add_product') }}</PrimaryButton>
            </Link>
        </div>

        <!-- Filtering is done by the server; every control here just puts a
             value in the query string and reloads. -->
        <div class="mb-4 rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
            <div class="flex flex-col gap-3">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="relative min-w-56 flex-1">
                        <TextInput
                            v-model="search"
                            type="search"
                            class="block w-full ps-9 text-sm"
                            :placeholder="$t('admin.products.search_placeholder')"
                            @input="onSearchInput"
                            @keyup.enter="reload"
                        />
                        <NavIcon name="search" class="pointer-events-none absolute inset-y-0 start-3 my-auto h-4 w-4 text-gray-400" />
                    </div>

                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        {{ $t('admin.products.showing_count', { shown: products.length, total: totalCount }) }}
                    </span>

                    <button
                        v-if="hasFilters"
                        type="button"
                        class="text-xs font-medium text-gray-500 underline hover:text-gray-800 dark:hover:text-gray-200"
                        @click="clearFilters"
                    >
                        {{ $t('admin.products.clear_filters') }}
                    </button>
                </div>

                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                    <select
                        class="focus-brand block w-full rounded-md border-gray-300 py-1.5 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                        :value="filters.menu_id ?? ''"
                        @change="reload({ menu_id: $event.target.value || null })"
                    >
                        <option value="">{{ $t('admin.products.all_menus') }}</option>
                        <option v-for="menu in filterOptions.menus" :key="menu.id" :value="menu.id">{{ menu.name }}</option>
                    </select>

                    <select
                        class="focus-brand block w-full rounded-md border-gray-300 py-1.5 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                        :value="filters.category_id ?? ''"
                        @change="reload({ category_id: $event.target.value || null })"
                    >
                        <option value="">{{ $t('admin.products.all_categories') }}</option>
                        <!-- The full trail, not just the section name: three
                             menus here each have a "مشروبات". -->
                        <option v-for="category in filterOptions.categories" :key="category.id" :value="category.id">
                            {{ category.path }}
                        </option>
                    </select>

                    <select
                        class="focus-brand block w-full rounded-md border-gray-300 py-1.5 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                        :value="filters.tag_id ?? ''"
                        @change="reload({ tag_id: $event.target.value || null })"
                    >
                        <option value="">{{ $t('admin.products.all_tags') }}</option>
                        <option v-for="tag in filterOptions.tags" :key="tag.id" :value="tag.id">{{ tag.name }}</option>
                    </select>

                    <select
                        class="focus-brand block w-full rounded-md border-gray-300 py-1.5 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                        :value="filters.station ?? ''"
                        @change="reload({ station: $event.target.value || null })"
                    >
                        <option value="">{{ $t('admin.products.all_stations') }}</option>
                        <option v-for="option in filterOptions.stations" :key="option.station" :value="option.station">
                            {{ option.name }}
                        </option>
                    </select>

                    <select
                        class="focus-brand block w-full rounded-md border-gray-300 py-1.5 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                        :value="filters.status ?? ''"
                        @change="reload({ status: $event.target.value || null })"
                    >
                        <option value="">{{ $t('admin.products.any_status') }}</option>
                        <option value="active">{{ $t('common.active') }}</option>
                        <option value="inactive">{{ $t('common.inactive') }}</option>
                    </select>

                    <select
                        class="focus-brand block w-full rounded-md border-gray-300 py-1.5 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                        :value="filters.placement ?? ''"
                        @change="reload({ placement: $event.target.value || null })"
                    >
                        <option value="">{{ $t('admin.products.any_placement') }}</option>
                        <option value="placed">{{ $t('admin.products.on_a_menu') }}</option>
                        <option value="unplaced">{{ $t('admin.products.not_on_a_menu') }}</option>
                    </select>
                </div>

                <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400">
                    <Checkbox
                        :checked="filters.translations === 'missing'"
                        @update:checked="(checked) => reload({ translations: checked ? 'missing' : null })"
                    />
                    {{ $t('admin.products.missing_translations_only') }}
                </label>
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                <thead class="bg-gray-50 dark:bg-gray-900/60">
                    <tr class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        <th class="w-8 px-2 py-3"></th>
                        <th class="px-4 py-3 text-start">{{ $t('common.name') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('admin.products.placements') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('common.price') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('common.status') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('admin.products.missing_translations') }}</th>
                        <th class="px-4 py-3 text-end">{{ $t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody v-if="products.length === 0">
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-sm text-gray-400">
                            {{ $t('admin.products.no_results') }}
                        </td>
                    </tr>
                </tbody>

                <draggable
                    v-else
                    v-model="rows"
                    tag="tbody"
                    item-key="id"
                    handle=".product-drag-handle"
                    class="divide-y divide-gray-200 dark:divide-gray-800"
                    @end="persistOrder"
                >
                    <template #item="{ element: product }">
                    <tr>
                        <td class="w-8 px-2 py-3 text-center">
                            <span
                                v-if="canReorder"
                                class="product-drag-handle cursor-move select-none text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                                :title="$t('admin.products.drag_to_reorder')"
                            >⋮⋮</span>
                            <span
                                v-else-if="rows.length > 1"
                                class="cursor-not-allowed select-none text-gray-200 dark:text-gray-700"
                                :title="reorderBlockedBy === 'filter' ? $t('admin.products.reorder_disabled_by_filter') : $t('admin.products.reorder_disabled_by_paging')"
                            >⋮⋮</span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <div class="flex items-center gap-3">
                                <div class="h-10 w-10 shrink-0 overflow-hidden rounded-md bg-gray-100 dark:bg-gray-800">
                                    <img v-if="product.image_url" :src="product.image_url" class="h-full w-full object-cover" :alt="product.name" />
                                    <img
                                        v-else-if="page.props.branding.logo_url"
                                        :src="page.props.branding.logo_url"
                                        class="h-full w-full object-contain p-1 opacity-70"
                                        :alt="product.name"
                                    />
                                </div>
                                <div>
                                    <div class="font-medium text-gray-900 dark:text-gray-100">{{ product.name }}</div>
                                    <div v-if="product.tags.length" class="mt-0.5 flex gap-1">
                                        <span v-for="tag in product.tags" :key="tag" class="text-xs text-gray-400">#{{ tag }}</span>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                            <!-- One chip per placement, not one per menu: the
                                 same product often sits in two sections of the
                                 same menu, and "which section" is the half an
                                 admin is actually looking for. -->
                            <div class="flex flex-col items-start gap-1">
                                <span v-if="product.placements.length === 0" class="text-xs text-gray-400">
                                    {{ $t('admin.products.no_placement') }}
                                </span>
                                <span
                                    v-for="placement in product.placements"
                                    :key="placement.id"
                                    class="inline-flex max-w-full items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs dark:bg-gray-800"
                                >
                                    <span class="truncate font-medium text-gray-600 dark:text-gray-300">{{ placement.menu ?? '—' }}</span>
                                    <span class="text-gray-400">›</span>
                                    <span class="truncate text-gray-500 dark:text-gray-400">{{ placement.category ?? '—' }}</span>
                                </span>
                            </div>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm tabular-nums text-gray-600 dark:text-gray-400">
                            {{ formatPrice(product.price_cents) }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <div class="flex items-center gap-2">
                                <ToggleSwitch
                                    :model-value="activeStates[product.id] ?? product.is_active"
                                    :pending="savingIds.includes(product.id)"
                                    :label="$t('admin.products.toggle_active')"
                                    @update:model-value="toggleActive(product)"
                                />
                                <span
                                    class="text-xs font-medium"
                                    :class="(activeStates[product.id] ?? product.is_active)
                                        ? 'text-green-700 dark:text-green-400'
                                        : 'text-gray-500 dark:text-gray-400'"
                                >
                                    {{ (activeStates[product.id] ?? product.is_active) ? $t('common.active') : $t('common.inactive') }}
                                </span>
                            </div>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <span v-if="product.missing_locales.length === 0" class="text-sm text-gray-400">—</span>
                            <span
                                v-for="code in product.missing_locales"
                                :key="code"
                                class="me-1 inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/40 dark:text-amber-400"
                            >
                                {{ languageName(code, languages) }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-end text-sm">
                            <div class="flex items-center justify-end gap-3">
                                <Link :href="route('admin.products.edit', product.id)" class="text-gray-500 hover:text-gray-800 dark:hover:text-gray-200">
                                    {{ $t('common.edit') }}
                                </Link>
                                <button type="button" class="text-red-600 hover:text-red-800" @click="destroy(product)">
                                    {{ $t('common.delete') }}
                                </button>
                            </div>
                        </td>
                    </tr>
                    </template>
                </draggable>
            </table>

            <Pagination :meta="pagination" />
        </div>

        <p v-if="rows.length > 1" class="mt-2 text-xs text-gray-400">
            {{ canReorder ? $t('admin.products.drag_hint') : (reorderBlockedBy === 'filter' ? $t('admin.products.reorder_disabled_by_filter') : $t('admin.products.reorder_disabled_by_paging')) }}
        </p>
    </AuthenticatedLayout>
</template>
