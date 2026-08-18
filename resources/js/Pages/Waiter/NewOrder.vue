<script setup>
import { computed, ref, watch } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import ProductCard from '@/Components/Customer/ProductCard.vue';
import ProductModal from '@/Components/Customer/ProductModal.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
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
        unit_price_cents: product.price_cents + selectedModifiers.reduce((sum, m) => sum + m.price_cents, 0),
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

function matchesSearch(product) {
    if (!search.value.trim()) return true;
    const term = search.value.trim().toLowerCase();
    return product.name.toLowerCase().includes(term) || (product.description ?? '').toLowerCase().includes(term);
}

function filterProducts(products) {
    return products.filter(matchesSearch);
}

const visibleCategories = computed(() =>
    categories.value
        .filter((category) => activeCategory.value === 'all' || activeCategory.value === category.id)
        .map((category) => ({
            ...category,
            products: filterProducts(category.products),
            subcategories: category.subcategories
                .map((sub) => ({ ...sub, products: filterProducts(sub.products) }))
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

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
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
                    <input
                        v-model="search"
                        type="search"
                        class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-500 focus:ring-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"
                        :placeholder="$t('menu.search_placeholder')"
                    />
                    <div class="mt-2 flex gap-2 overflow-x-auto pb-1">
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

                <p v-if="visibleCategories.length === 0" class="mt-10 text-center text-sm text-gray-400">{{ $t('menu.no_results') }}</p>

                <section v-for="category in visibleCategories" :key="category.id" class="mb-6">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ category.name }}</h2>

                    <div v-if="category.products.length > 0" class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-3">
                        <ProductCard
                            v-for="product in category.products"
                            :key="product.id"
                            :product="product"
                            :currency-symbol="branding.currency_symbol"
                            wrap-name
                            @add="onAddClick"
                        />
                    </div>

                    <div v-for="sub in category.subcategories" :key="sub.id" class="mt-4">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400">{{ sub.name }}</h3>
                        <div class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-3">
                            <ProductCard
                                v-for="product in sub.products"
                                :key="product.id"
                                :product="product"
                                :currency-symbol="branding.currency_symbol"
                                wrap-name
                                @add="onAddClick"
                            />
                        </div>
                    </div>
                </section>
            </div>

            <!-- Order summary / checkout -->
            <div class="lg:sticky lg:top-6 lg:self-start">
                <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                    <h3 class="mb-3 font-semibold text-gray-900 dark:text-gray-100">{{ $t('menu.your_order') }}</h3>

                    <p v-if="lines.length === 0" class="text-sm text-gray-400">{{ $t('menu.empty_cart') }}</p>

                    <div v-for="line in lines" :key="line.id" class="mb-3 flex items-start gap-2 border-b border-gray-100 pb-3 dark:border-gray-800">
                        <div class="flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ line.name }}</p>
                            <p v-if="line.modifiers.length" class="text-xs text-gray-500">{{ line.modifiers.map((m) => m.name).join(', ') }}</p>
                            <div class="mt-1 flex items-center gap-2">
                                <button type="button" class="flex h-6 w-6 items-center justify-center rounded-full border border-gray-300 text-xs dark:border-gray-600" @click="updateQuantity(line.id, line.quantity - 1)">−</button>
                                <span class="w-4 text-center text-sm">{{ line.quantity }}</span>
                                <button type="button" class="flex h-6 w-6 items-center justify-center rounded-full border border-gray-300 text-xs dark:border-gray-600" @click="updateQuantity(line.id, line.quantity + 1)">+</button>
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
