<script setup>
import { computed, ref, watch } from 'vue';
import CartDrawer from '@/Components/Customer/CartDrawer.vue';
import LanguageSwitcher from '@/Components/LanguageSwitcher.vue';
import ProductCard from '@/Components/Customer/ProductCard.vue';
import ProductModal from '@/Components/Customer/ProductModal.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import { useCart } from '@/composables/useCart';
import { Head, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    menus: {
        type: Array,
        required: true,
    },
});

const page = usePage();
const { t } = useI18n();
const cart = useCart();

const branding = computed(() => page.props.branding);

// Set by the QR code printed on each table (Admin → Tables → Print QR),
// which links to /menu?table_id={id} — links the order back to the right
// table on the floor plan automatically instead of relying on a manually
// typed table number. The QR never encodes a specific menu — which
// menu(s) are visible right now is resolved server-side on every load.
const tableId = new URLSearchParams(window.location.search).get('table_id');
const tableLabel = new URLSearchParams(window.location.search).get('table_label');

const activeMenuId = ref(props.menus[0]?.id ?? null);
const activeMenu = computed(() => props.menus.find((m) => m.id === activeMenuId.value) ?? props.menus[0]);
const categories = computed(() => activeMenu.value?.categories ?? []);

const search = ref('');
const activeCategory = ref('all');
const selectedProduct = ref(null);
const showCart = ref(false);

const flatCategories = computed(() => categories.value.map((c) => ({ id: c.id, name: c.name })));

watch(activeMenuId, () => {
    activeCategory.value = 'all';
    search.value = '';
});

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

// Always open the full detail view first — a customer should see the photo,
// description, and any customization options before it's added, rather
// than a plain item being silently dropped into the cart on tap.
function onAddClick(product) {
    selectedProduct.value = product;
}

function onModalAdd({ selectedModifiers, quantity, notes }) {
    cart.add(selectedProduct.value, selectedModifiers, quantity, notes);
    selectedProduct.value = null;
}

function formatPrice(cents) {
    return `${branding.value.currency_symbol}${(cents / 100).toFixed(2)}`;
}

// Lets a customer flag their table without placing an order first (e.g. to
// ask a question or request something off-menu). Disabled for a short
// cooldown after calling so an impatient tap doesn't spam the floor.
const waiterCalled = ref(false);
const callingWaiter = ref(false);

function callWaiter() {
    if (callingWaiter.value || waiterCalled.value) return;
    callingWaiter.value = true;

    router.post(route('waiter-calls.store'), {
        table_id: tableId,
        table_number: tableId ? null : tableLabel,
    }, {
        preserveScroll: true,
        onFinish: () => { callingWaiter.value = false; },
        onSuccess: () => {
            waiterCalled.value = true;
            setTimeout(() => { waiterCalled.value = false; }, 60000);
        },
    });
}
</script>

<template>
    <Head :title="t('menu.title')" />

    <div class="min-h-screen bg-white pb-28 dark:bg-gray-950">
        <!-- Hero -->
        <div class="relative h-64 w-full overflow-hidden sm:h-72">
            <img v-if="branding.cover_image_url" :src="branding.cover_image_url" class="absolute inset-0 h-full w-full object-cover" alt="" />
            <div v-else class="absolute inset-0" :style="{ backgroundColor: branding.primary_color }"></div>
            <div class="absolute inset-0 bg-gradient-to-t from-black/85 via-black/35 to-black/10"></div>

            <div class="absolute inset-x-0 top-0 flex items-start justify-between p-4">
                <div class="flex flex-col items-start gap-1.5">
                    <span
                        v-if="tableLabel"
                        class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1 text-xs font-medium text-white backdrop-blur-sm"
                    >
                        {{ t('tables.title') }} {{ tableLabel }}
                    </span>
                    <button
                        v-if="tableId || tableLabel"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1 text-xs font-medium text-white backdrop-blur-sm transition hover:bg-white/25 disabled:opacity-60"
                        :disabled="callingWaiter || waiterCalled"
                        @click="callWaiter"
                    >
                        🔔 {{ waiterCalled ? t('menu.waiter_on_the_way') : t('menu.call_waiter') }}
                    </button>
                </div>
                <div class="flex items-center gap-1.5">
                    <ThemeToggle variant="inverted" />
                    <LanguageSwitcher />
                </div>
            </div>

            <div class="absolute inset-x-0 bottom-0 flex flex-col items-center px-6 pb-7 text-center text-white">
                <div v-if="branding.logo_url" class="mb-3 h-20 w-20 overflow-hidden rounded-full bg-white/10 shadow-xl ring-4 ring-white/25 backdrop-blur">
                    <img :src="branding.logo_url" class="h-full w-full object-cover" />
                </div>
                <h1 class="text-3xl font-bold tracking-tight">{{ branding.name }}</h1>
                <p v-if="branding.tagline" class="mx-auto mt-1.5 max-w-xs text-sm text-white/85">{{ branding.tagline }}</p>
            </div>
        </div>

        <p v-if="menus.length === 0" class="px-6 py-16 text-center text-sm text-gray-400">{{ t('menu.empty') }}</p>

        <!-- Menu switcher: an elevated segmented control, not a plain pill row —
             deliberately impossible to miss when more than one menu is live. -->
        <div v-if="menus.length > 1" class="relative z-20 -mt-6 px-4">
            <div class="mx-auto flex max-w-lg gap-1 rounded-2xl border border-gray-200 bg-white p-1.5 shadow-lg shadow-black/5 dark:border-gray-800 dark:bg-gray-900">
                <button
                    v-for="menu in menus"
                    :key="menu.id"
                    type="button"
                    class="flex-1 rounded-xl px-3 py-2.5 text-sm font-semibold transition-all duration-200"
                    :class="activeMenuId === menu.id
                        ? 'text-white shadow-sm'
                        : 'text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200'"
                    :style="activeMenuId === menu.id ? { backgroundColor: branding.primary_color } : {}"
                    @click="activeMenuId = menu.id"
                >
                    {{ menu.name }}
                </button>
            </div>
        </div>

        <!-- Search + category filters -->
        <div class="sticky top-0 z-10 border-b border-gray-100 bg-white/95 px-4 pb-3 backdrop-blur dark:border-gray-800 dark:bg-gray-950/95" :class="menus.length > 1 ? 'pt-4' : 'pt-3'">
            <div class="relative mx-auto max-w-lg">
                <input
                    v-model="search"
                    type="search"
                    class="block w-full rounded-full border-gray-200 bg-gray-50 text-sm shadow-none focus:border-gray-400 focus:ring-gray-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"
                    :placeholder="t('menu.search_placeholder')"
                />
            </div>
            <div class="mx-auto mt-3 flex max-w-lg gap-2 overflow-x-auto pb-1">
                <button
                    type="button"
                    class="shrink-0 rounded-full px-3.5 py-1.5 text-sm font-medium transition-colors"
                    :class="activeCategory === 'all'
                        ? 'text-white'
                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700'"
                    :style="activeCategory === 'all' ? { backgroundColor: branding.primary_color } : {}"
                    @click="activeCategory = 'all'"
                >
                    {{ t('menu.all_categories') }}
                </button>
                <button
                    v-for="category in flatCategories"
                    :key="category.id"
                    type="button"
                    class="shrink-0 rounded-full px-3.5 py-1.5 text-sm font-medium transition-colors"
                    :class="activeCategory === category.id
                        ? 'text-white'
                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700'"
                    :style="activeCategory === category.id ? { backgroundColor: branding.primary_color } : {}"
                    @click="activeCategory = category.id"
                >
                    {{ category.name }}
                </button>
            </div>
        </div>

        <!-- Menu sections -->
        <main class="mx-auto max-w-lg px-5 py-6">
            <p v-if="visibleCategories.length === 0" class="mt-10 text-center text-sm text-gray-400">{{ t('menu.no_results') }}</p>

            <section v-for="category in visibleCategories" :key="category.id" class="mb-10">
                <div class="mb-1 flex items-center gap-3">
                    <h2 class="shrink-0 text-lg font-semibold tracking-tight text-gray-900 dark:text-gray-100">{{ category.name }}</h2>
                    <span class="h-px flex-1" :style="{ backgroundColor: branding.primary_color, opacity: 0.25 }"></span>
                </div>
                <p v-if="category.description" class="mb-2 text-sm text-gray-500">{{ category.description }}</p>

                <div v-if="category.products.length > 0" class="grid grid-cols-2 gap-3">
                    <ProductCard
                        v-for="product in category.products"
                        :key="product.id"
                        :product="product"
                        :currency-symbol="branding.currency_symbol"
                        @add="onAddClick"
                    />
                </div>

                <div v-for="sub in category.subcategories" :key="sub.id" class="mt-6">
                    <h3 class="mb-1 text-xs font-semibold uppercase tracking-widest text-gray-400">{{ sub.name }}</h3>
                    <div class="grid grid-cols-2 gap-3">
                        <ProductCard
                            v-for="product in sub.products"
                            :key="product.id"
                            :product="product"
                            :currency-symbol="branding.currency_symbol"
                            @add="onAddClick"
                        />
                    </div>
                </div>
            </section>
        </main>

        <!-- Floating cart button -->
        <button
            v-if="cart.count.value > 0"
            type="button"
            class="fixed inset-x-4 bottom-4 z-30 flex items-center justify-between rounded-full px-5 py-3.5 text-white shadow-lg"
            style="background-color: var(--brand-primary)"
            @click="showCart = true"
        >
            <span class="flex items-center gap-2 text-sm font-medium">
                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-white/20 text-xs">{{ cart.count.value }}</span>
                {{ t('menu.view_cart') }}
            </span>
            <span class="font-semibold">{{ formatPrice(cart.totalCents.value) }}</span>
        </button>

        <ProductModal
            v-if="selectedProduct"
            :product="selectedProduct"
            :currency-symbol="branding.currency_symbol"
            @close="selectedProduct = null"
            @add="onModalAdd"
        />

        <CartDrawer
            v-if="showCart"
            :currency-symbol="branding.currency_symbol"
            :table-id="tableId"
            :table-label="tableLabel"
            @close="showCart = false"
        />
    </div>
</template>
