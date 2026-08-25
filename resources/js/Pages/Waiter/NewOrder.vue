<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import NavIcon from '@/Components/NavIcon.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import ProductCard from '@/Components/Customer/ProductCard.vue';
import ProductModal from '@/Components/Customer/ProductModal.vue';
import QuickAddResult from '@/Components/Staff/QuickAddResult.vue';
import { unitPriceCents } from '@/composables/useProductPricing';
import { matchesQuery, searchMenus } from '@/composables/useProductSearch';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    menus: {
        type: Array,
        required: true,
    },
    tables: {
        type: Array,
        required: true,
    },
    preselectedTableId: {
        type: Number,
        default: null,
    },
    existingOrder: {
        type: Object,
        default: null,
    },
});

const preselectedTable = computed(() => props.tables.find((t) => t.id === props.preselectedTableId) ?? null);

const { t } = useI18n();
const page = usePage();
const branding = computed(() => page.props.branding);

function formatPrice(cents) {
    return `${branding.value.currency_symbol}${(cents / 100).toFixed(2)}`;
}

// A separate, non-persisted cart local to this screen — the waiter's
// in-progress order must never mix with a customer's own cart that might
// be sitting in this same browser's localStorage.
const lines = ref([]);
let idCounter = 0;

function dedupKey(menuItemId, modifierIds) {
    return `${menuItemId}:${[...modifierIds].sort((a, b) => a - b).join(',')}`;
}

function addToCart(product, selectedModifiers = [], quantity = 1, notes = '') {
    const modifierIds = selectedModifiers.map((m) => m.id);
    const dedup = dedupKey(product.menu_item_id, modifierIds);

    if (!notes) {
        const existing = lines.value.find((line) => line.dedupKey === dedup && !line.notes);
        if (existing) {
            existing.quantity += quantity;
            return;
        }
    }

    idCounter += 1;
    lines.value.push({
        id: `line-${idCounter}`,
        dedupKey: dedup,
        product_id: product.id,
        menu_item_id: product.menu_item_id,
        name: product.name,
        // Shared with the customer cart and the server — a size replaces
        // the price, an extra adds to it.
        unit_price_cents: unitPriceCents(product, selectedModifiers),
        modifiers: selectedModifiers.map((m) => ({ id: m.id, name: m.name, price_cents: m.price_cents })),
        quantity,
        notes,
    });
}

function updateQuantity(id, quantity) {
    const line = lines.value.find((l) => l.id === id);
    if (!line) return;
    if (quantity <= 0) {
        lines.value = lines.value.filter((l) => l.id !== id);
        return;
    }
    line.quantity = quantity;
}

const cartTotalCents = computed(() => lines.value.reduce((sum, l) => sum + l.unit_price_cents * l.quantity, 0));
const cartCount = computed(() => lines.value.reduce((sum, l) => sum + l.quantity, 0));

// Menu browsing state (mirrors the customer menu page's UX).
const activeMenuId = ref(props.menus[0]?.id ?? null);
const activeMenu = computed(() => props.menus.find((m) => m.id === activeMenuId.value) ?? props.menus[0]);
const categories = computed(() => activeMenu.value?.categories ?? []);
const flatCategories = computed(() => categories.value.map((c) => ({ id: c.id, name: c.name })));
const activeCategory = ref('all');
const search = ref('');
const selectedProduct = ref(null);

watch(activeMenuId, () => { activeCategory.value = 'all'; search.value = ''; });

// Typing is the fast path, so it takes over the screen rather than
// filtering underneath it: one flat best-first list across every menu,
// which is what makes "the top one" a thing Enter can add.
const isSearching = computed(() => search.value.trim().length > 0);
const results = computed(() => (isSearching.value ? searchMenus(props.menus, search.value) : []));

const searchBox = ref(null);
const resultList = ref(null);
const highlighted = ref(0);

// Arrowing past the fold used to move a cursor the waiter could not see:
// the list kept scrolling from the top while the highlight walked off the
// bottom of the screen. `nearest` keeps the page still whenever the row is
// already visible, so ordinary typing never jumps the view around.
watch(highlighted, (index) => {
    nextTick(() => {
        resultList.value?.children?.[index]?.scrollIntoView({ block: 'nearest' });
    });
});

// A new query invalidates the old cursor — leaving it where it was means
// Enter adds whatever happens to sit at that position now.
watch(results, () => { highlighted.value = 0; });

/** How many of this dish are already on the order, for the row's badge. */
function inCart(product) {
    return lines.value
        .filter((line) => line.menu_item_id === product.menu_item_id)
        .reduce((sum, line) => sum + line.quantity, 0);
}

function moveHighlight(step) {
    if (results.value.length === 0) return;

    const next = highlighted.value + step;
    highlighted.value = (next + results.value.length) % results.value.length;
}

/**
 * Enter adds the highlighted dish and leaves the box ready for the next
 * one. The waiter is reading an order off a table, not navigating an app:
 * "شاي ⏎ قهوة ⏎ مياه ⏎" has to work without a hand leaving the keyboard.
 *
 * The query is cleared rather than kept, because the next thing typed is
 * always a different dish.
 */
function addHighlighted() {
    const product = results.value[highlighted.value];

    if (!product) return;

    onAddClick(product);

    // A dish with required choices opens the sheet; clearing the search
    // behind it would drop the waiter back to a blank browse on close.
    if (product.modifier_groups.length === 0) {
        search.value = '';
    }
}

function filterProducts(products, context) {
    return products.filter((product) => matchesQuery(product, search.value, context));
}

const visibleCategories = computed(() =>
    categories.value
        .filter((category) => activeCategory.value === 'all' || activeCategory.value === category.id)
        .map((category) => ({
            ...category,
            products: filterProducts(category.products, category.name),
            subcategories: category.subcategories
                // A subcategory carries its parent's name too, so typing the
                // shelf ("مشروبات") keeps everything under it, not just the
                // dishes whose own subcategory happens to repeat the word.
                .map((sub) => ({ ...sub, products: filterProducts(sub.products, `${category.name} ${sub.name}`) }))
                .filter((sub) => sub.products.length > 0),
        }))
        .filter((category) => category.products.length > 0 || category.subcategories.length > 0),
);

function onAddClick(product) {
    if (product.modifier_groups.length > 0) {
        selectedProduct.value = product;
        return;
    }
    addToCart(product, [], 1);
}

function onModalAdd({ selectedModifiers, quantity, notes }) {
    addToCart(selectedProduct.value, selectedModifiers, quantity, notes);
    selectedProduct.value = null;

    // Straight back to typing the next dish, which is where the waiter was
    // before the sheet interrupted them.
    search.value = '';
    nextTick(() => searchBox.value?.focus());
}

// The cursor lands in the search box on arrival. This screen has exactly
// one thing a waiter does first, and making them tap the field before they
// can type it is a step that exists for no reason.
onMounted(() => searchBox.value?.focus());

// Where "back" goes: the staff screen this person actually has, reopened
// on the table they came in from. The route name is read off the shared
// auth props rather than hardcoded, because a cashier pressing back on
// /waiter would be answered with a 403.
const backHref = computed(() => {
    const home = page.props.auth.home_route ?? 'waiter.index';

    return route(home, props.preselectedTableId ? { table: props.preselectedTableId } : {});
});

// On one column the order panel is below the menu, so a waiter adding
// dishes cannot see it. A sticky strip keeps the running count and total in
// view and scrolls down to the panel on tap.
//
// `sticky` and not `fixed`: it stays inside the document flow, so it
// reserves its own space and can never sit on top of the content the way
// the docked sheet this replaced did.
const cartPanel = ref(null);

function scrollToCart() {
    cartPanel.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// Order details + submit.
const form = useForm({
    table_id: props.preselectedTableId,
    table_number: '',
    guest_count: null,
    customer_name: '',
    notes: '',
    items: [],
});

const useTableSelect = ref(true);

function submit() {
    const items = lines.value.map((line) => ({
        menu_item_id: line.menu_item_id,
        quantity: line.quantity,
        notes: line.notes || null,
        modifier_ids: line.modifiers.map((m) => m.id),
    }));

    if (props.existingOrder) {
        form.transform(() => ({ items })).post(route('waiter.orders.addItems', props.existingOrder.id));
        return;
    }

    form.items = items;
    form.post(route('waiter.orders.store'));
}
</script>

<template>
    <Head :title="$t('waiter.new_order')" />

    <AuthenticatedLayout>
        <template #header>
            {{ existingOrder ? $t('waiter.add_items_to', { table: preselectedTable?.label }) : $t('waiter.new_order') }}
        </template>

        <!-- Back to the floor, and back to *this* table. Leaving without
             ordering used to land on the top of the room with nothing open,
             so a waiter who opened table 20, changed their mind and pressed
             back had to find table 20 again. -->
        <Link :href="backHref" class="mb-3 inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">
            <NavIcon name="chevronLeft" class="h-4 w-4 rtl:rotate-180" />
            <span>{{ preselectedTable ? $t('waiter.back_to_table', { table: preselectedTable.label }) : $t('common.back') }}</span>
        </Link>

        <!-- Two columns only once there is genuinely room for two.
             It used to split at md (768px) and only widen the order panel at
             xl (1280px) — a range no iPad is ever in. Both orientations
             therefore landed on the same squeezed ~288px panel: the quantity
             steppers, the table picker and the notes field all stacked into
             a column too narrow to read, on the device the floor actually
             uses. Below xl it now stacks, and the order becomes a sheet
             pinned to the bottom of the screen. -->
        <div class="grid gap-4 lg:grid-cols-12">
            <div class="lg:col-span-8">
                <!-- One column only: the order panel is further down the
                     page, so this is the only thing telling the waiter what
                     they have added so far. -->
                <button
                    v-if="cartCount > 0"
                    type="button"
                    class="sticky top-2 z-20 mb-3 flex w-full items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white/95 px-4 py-2.5 shadow-sm backdrop-blur lg:hidden dark:border-gray-700 dark:bg-gray-900/95"
                    @click="scrollToCart"
                >
                    <span class="flex items-center gap-2">
                        <span
                            class="rounded-full px-2 py-0.5 text-xs font-bold text-white"
                            :style="{ backgroundColor: 'var(--brand-primary)' }"
                        >{{ cartCount }}</span>
                        <span class="text-sm text-gray-600 dark:text-gray-300">{{ $t('menu.your_order') }}</span>
                    </span>
                    <span class="text-sm font-bold tabular-nums text-gray-900 dark:text-gray-100">{{ formatPrice(cartTotalCents) }}</span>
                </button>
                <div v-if="menus.length > 1" class="mb-3 flex gap-2 overflow-x-auto pb-1">
                    <button
                        v-for="menu in menus"
                        :key="menu.id"
                        type="button"
                        class="shrink-0 rounded-full border px-4 py-1.5 text-sm font-medium"
                        :class="activeMenuId === menu.id
                            ? 'border-transparent text-white'
                            : 'border-gray-200 text-gray-600 dark:border-gray-700 dark:text-gray-300'"
                        :style="activeMenuId === menu.id ? { backgroundColor: 'var(--brand-primary)' } : {}"
                        @click="activeMenuId = menu.id"
                    >
                        {{ menu.name }}
                    </button>
                </div>

                <div class="mb-3">
                    <!-- Arrow keys move the cursor, Enter adds, Esc clears —
                         so a whole round can be typed without the hand
                         leaving the keyboard. .prevent on the arrows stops
                         the caret jumping around the text while it does. -->
                    <input
                        ref="searchBox"
                        v-model="search"
                        type="search"
                        class="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-gray-500 focus:ring-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"
                        :placeholder="$t('menu.quick_add_placeholder')"
                        @keydown.down.prevent="moveHighlight(1)"
                        @keydown.up.prevent="moveHighlight(-1)"
                        @keydown.enter.prevent="addHighlighted"
                        @keydown.esc="search = ''"
                    />
                    <p v-if="isSearching" class="mt-1 text-[11px] text-gray-400">
                        {{ $t('menu.quick_add_hint', { count: results.length }) }}
                    </p>
                    <!-- Categories are for browsing, and searching is the
                         opposite of browsing: while there is a query the
                         chips are answering a question nobody asked. -->
                    <div v-if="!isSearching" class="mt-2 flex gap-2 overflow-x-auto pb-1">
                        <button
                            type="button"
                            class="shrink-0 rounded-full border px-3.5 py-1.5 text-sm font-medium"
                            :class="activeCategory === 'all'
                                ? 'border-transparent text-white'
                                : 'border-gray-200 text-gray-600 dark:border-gray-700 dark:text-gray-300'"
                            :style="activeCategory === 'all' ? { backgroundColor: 'var(--brand-primary)' } : {}"
                            @click="activeCategory = 'all'"
                        >
                            {{ $t('menu.all_categories') }}
                        </button>
                        <button
                            v-for="category in flatCategories"
                            :key="category.id"
                            type="button"
                            class="shrink-0 rounded-full border px-3.5 py-1.5 text-sm font-medium"
                            :class="activeCategory === category.id
                                ? 'border-transparent text-white'
                                : 'border-gray-200 text-gray-600 dark:border-gray-700 dark:text-gray-300'"
                            :style="activeCategory === category.id ? { backgroundColor: 'var(--brand-primary)' } : {}"
                            @click="activeCategory = category.id"
                        >
                            {{ category.name }}
                        </button>
                    </div>
                </div>

                <!-- Search results: one flat list, best match first, across
                     every menu. Not grouped by category — grouping puts the
                     waiter back to scanning headings, which is the thing
                     they typed to avoid. -->
                <div v-if="isSearching" ref="resultList" class="space-y-1.5 [&>button]:scroll-mt-16">
                    <p v-if="results.length === 0" class="mt-10 text-center text-sm text-gray-400">{{ $t('menu.no_results') }}</p>

                    <QuickAddResult
                        v-for="(product, index) in results"
                        :key="`${product.menu_item_id}`"
                        :product="product"
                        :currency-symbol="branding.currency_symbol"
                        :path="product.categoryPath"
                        :highlighted="index === highlighted"
                        :in-cart="inCart(product)"
                        @add="onAddClick"
                    />
                </div>

                <template v-else>
                <p v-if="visibleCategories.length === 0" class="mt-10 text-center text-sm text-gray-400">{{ $t('menu.no_results') }}</p>

                <section v-for="category in visibleCategories" :key="category.id" class="mb-6">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ category.name }}</h2>

                    <div v-if="category.products.length > 0" class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-2 2xl:grid-cols-3">
                        <ProductCard
                            v-for="product in category.products"
                            :key="product.id"
                            :product="product"
                            :currency-symbol="branding.currency_symbol"
                            :in-cart="inCart(product)"
                            wrap-name
                            @add="onAddClick"
                        />
                    </div>

                    <div v-for="sub in category.subcategories" :key="sub.id" class="mt-4">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400">{{ sub.name }}</h3>
                        <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-2 2xl:grid-cols-3">
                            <ProductCard
                                v-for="product in sub.products"
                                :key="product.id"
                                :product="product"
                                :currency-symbol="branding.currency_symbol"
                                :in-cart="inCart(product)"
                                wrap-name
                                @add="onAddClick"
                            />
                        </div>
                    </div>
                </section>
                </template>
            </div>

            <!-- Order summary / checkout.
                 Ordinary document flow at every size: a sticky sidebar once
                 there are two columns, and simply the next block down when
                 there is one. It was briefly a fixed sheet docked to the
                 bottom of the screen, which is a lot of positioning to get
                 right for very little — on a tablet it covered most of the
                 menu the moment a dish was added. A panel that cannot
                 overlap anything cannot be in the way. -->
            <!-- Sticky from lg, where the two columns start: the order has
                 to stay in view while the menu scrolls under it, otherwise
                 adding a tenth dish means scrolling back up to check the
                 ninth landed. It also caps its own height and scrolls
                 inside, because a long order that outgrows the viewport
                 makes `sticky` silently stop sticking. -->
            <div class="lg:sticky lg:top-6 lg:col-span-4 lg:self-start">
                <div ref="cartPanel" class="rounded-lg border border-gray-200 bg-white p-4 lg:max-h-[calc(100vh-6rem)] lg:overflow-y-auto lg:overscroll-contain dark:border-gray-800 dark:bg-gray-900">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $t('menu.your_order') }}</span>

                        <span v-if="cartCount > 0" class="flex items-center gap-2">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-bold text-white"
                                :style="{ backgroundColor: 'var(--brand-primary)' }"
                            >{{ cartCount }}</span>
                            <span class="text-sm font-bold tabular-nums text-gray-900 dark:text-gray-100">{{ formatPrice(cartTotalCents) }}</span>
                        </span>
                    </div>

                    <p v-if="lines.length === 0" class="text-sm text-gray-400">{{ $t('menu.empty_cart') }}</p>

                    <div v-for="line in lines" :key="line.id" class="mb-3 flex items-start gap-2 border-b border-gray-100 pb-3 dark:border-gray-800">
                        <div class="flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ line.name }}</p>
                            <p v-if="line.modifiers.length" class="text-xs text-gray-500">{{ line.modifiers.map((m) => m.name).join(', ') }}</p>
                            <div class="mt-1 flex items-center gap-2">
                                <button type="button" class="flex h-9 w-9 items-center justify-center rounded-full border border-gray-300 text-base leading-none dark:border-gray-600" @click="updateQuantity(line.id, line.quantity - 1)">−</button>
                                <span class="w-4 text-center text-sm">{{ line.quantity }}</span>
                                <button type="button" class="flex h-9 w-9 items-center justify-center rounded-full border border-gray-300 text-base leading-none dark:border-gray-600" @click="updateQuantity(line.id, line.quantity + 1)">+</button>
                            </div>
                        </div>
                        <p class="shrink-0 text-sm font-medium text-gray-900 dark:text-gray-100">{{ formatPrice(line.unit_price_cents * line.quantity) }}</p>
                    </div>

                    <div v-if="lines.length > 0" class="space-y-3">
                        <!-- Adding to a table's already-running order: no need to ask for
                             table/guests/name again, just confirm what's already open. -->
                        <div v-if="existingOrder" class="rounded-md bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                            {{ existingOrder.reference }} · {{ $t('staff.orders_count', { count: existingOrder.items_count }) }} · {{ formatPrice(existingOrder.total_cents) }}
                        </div>

                        <template v-else>
                            <div v-if="preselectedTable" class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                {{ $t('tables.title') }}: {{ preselectedTable.label }}
                            </div>
                            <template v-else>
                                <div class="flex gap-2 text-xs">
                                    <button type="button" class="rounded-md px-2 py-1" :class="useTableSelect ? 'text-white' : 'bg-gray-100 text-gray-500 dark:bg-gray-800'" :style="useTableSelect ? { backgroundColor: 'var(--brand-primary)' } : {}" @click="useTableSelect = true">
                                        {{ $t('tables.title') }}
                                    </button>
                                    <button type="button" class="rounded-md px-2 py-1" :class="!useTableSelect ? 'text-white' : 'bg-gray-100 text-gray-500 dark:bg-gray-800'" :style="!useTableSelect ? { backgroundColor: 'var(--brand-primary)' } : {}" @click="useTableSelect = false">
                                        {{ $t('menu.table_number') }}
                                    </button>
                                </div>

                                <select
                                    v-if="useTableSelect"
                                    v-model.number="form.table_id"
                                    class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-500 focus:ring-gray-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                                >
                                    <option :value="null">—</option>
                                    <option v-for="tbl in tables" :key="tbl.id" :value="tbl.id">
                                        {{ tbl.label }} {{ tbl.is_occupied ? `(${$t('tables.occupied')})` : '' }}
                                    </option>
                                </select>
                                <input
                                    v-else
                                    v-model="form.table_number"
                                    type="text"
                                    class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-500 focus:ring-gray-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                                    :placeholder="$t('menu.table_number')"
                                />
                            </template>

                            <input
                                v-model.number="form.guest_count"
                                type="number"
                                min="1"
                                class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-500 focus:ring-gray-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                                :placeholder="$t('menu.guest_count')"
                            />
                            <input
                                v-model="form.customer_name"
                                type="text"
                                class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-500 focus:ring-gray-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                                :placeholder="$t('menu.customer_name')"
                            />
                            <textarea
                                v-model="form.notes"
                                rows="2"
                                class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-500 focus:ring-gray-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                                :placeholder="$t('menu.order_notes')"
                            ></textarea>
                        </template>

                        <p v-if="form.errors.items" class="text-sm text-red-600">{{ form.errors.items }}</p>

                        <div class="flex items-center justify-between border-t border-gray-100 pt-3 font-semibold text-gray-900 dark:border-gray-800 dark:text-gray-100">
                            <span>{{ $t('common.price') }} ({{ cartCount }})</span>
                            <span>{{ formatPrice(cartTotalCents) }}</span>
                        </div>

                        <PrimaryButton class="w-full justify-center" :disabled="form.processing" @click="submit">
                            {{ existingOrder ? $t('waiter.add_to_order') : $t('menu.place_order') }}
                        </PrimaryButton>
                    </div>
                </div>
            </div>
        </div>

        <ProductModal
            v-if="selectedProduct"
            :product="selectedProduct"
            :currency-symbol="branding.currency_symbol"
            @close="selectedProduct = null"
            @add="onModalAdd"
        />
    </AuthenticatedLayout>
</template>
