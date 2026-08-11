<script setup>
import { computed, ref } from 'vue';
import CartDrawer from '@/Components/Customer/CartDrawer.vue';
import LanguageSwitcher from '@/Components/LanguageSwitcher.vue';
import ProductCard from '@/Components/Customer/ProductCard.vue';
import ProductModal from '@/Components/Customer/ProductModal.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import { useCart } from '@/composables/useCart';
import { Head, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    categories: {
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
// typed table number.
const tableId = new URLSearchParams(window.location.search).get('table_id');
const tableLabel = new URLSearchParams(window.location.search).get('table_label');

const search = ref('');
const activeCategory = ref('all');
const selectedProduct = ref(null);
const showCart = ref(false);

const flatCategories = computed(() => props.categories.map((c) => ({ id: c.id, name: c.name })));

function matchesSearch(product) {
    if (!search.value.trim()) return true;
    const term = search.value.trim().toLowerCase();
    return product.name.toLowerCase().includes(term) || (product.description ?? '').toLowerCase().includes(term);
}

function filterProducts(products) {
    return products.filter(matchesSearch);
}

const visibleCategories = computed(() =>
    props.categories
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
    cart.add(product, [], 1);
}

function onModalAdd({ selectedModifiers, quantity, notes }) {
    cart.add(selectedProduct.value, selectedModifiers, quantity, notes);
    selectedProduct.value = null;
}

function formatPrice(cents) {
    return `${branding.value.currency_symbol}${(cents / 100).toFixed(2)}`;
}
</script>

<template>
    <Head :title="t('menu.title')" />

    <div class="min-h-screen bg-gray-50 pb-24 dark:bg-gray-950">
        <!-- Hero -->
        <div
            class="relative flex flex-col items-center overflow-hidden px-6 pb-10 pt-8 text-center text-white"
            :style="{
                backgroundColor: branding.primary_color,
                backgroundImage: branding.cover_image_url ? `linear-gradient(0deg, rgba(0,0,0,.45), rgba(0,0,0,.45)), url(${branding.cover_image_url})` : undefined,
                backgroundSize: 'cover',
                backgroundPosition: 'center',
            }"
        >
            <div class="absolute end-4 top-4 flex items-center gap-1.5">
                <ThemeToggle variant="inverted" />
                <LanguageSwitcher />
            </div>

            <div v-if="branding.logo_url" class="mb-3 h-16 w-16 overflow-hidden rounded-full ring-2 ring-white/70">
                <img :src="branding.logo_url" class="h-full w-full object-cover" />
            </div>
            <h1 class="text-2xl font-bold">{{ branding.name }}</h1>
            <p v-if="branding.tagline" class="mt-1 text-sm text-white/80">{{ branding.tagline }}</p>

            <span v-if="tableLabel" class="mt-3 inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1 text-xs font-medium">
                {{ t('tables.title') }} {{ tableLabel }}
            </span>
        </div>

        <!-- Search + filters -->
        <div class="sticky top-0 z-20 border-b border-gray-200 bg-white/95 px-4 py-3 backdrop-blur dark:border-gray-800 dark:bg-gray-950/95">
            <input
                v-model="search"
                type="search"
                class="block w-full rounded-full border-gray-300 bg-gray-50 text-sm shadow-sm focus:border-gray-500 focus:ring-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"
                :placeholder="t('menu.search_placeholder')"
            />
            <div class="mt-3 flex gap-2 overflow-x-auto pb-1">
                <button
                    type="button"
                    class="shrink-0 rounded-full border px-3.5 py-1.5 text-sm font-medium"
                    :class="activeCategory === 'all' ? 'border-transparent text-white' : 'border-gray-200 text-gray-600 dark:border-gray-700 dark:text-gray-300'"
                    :style="activeCategory === 'all' ? { backgroundColor: branding.primary_color } : {}"
                    @click="activeCategory = 'all'"
                >
                    {{ t('menu.all_categories') }}
                </button>
                <button
                    v-for="category in flatCategories"
                    :key="category.id"
                    type="button"
                    class="shrink-0 rounded-full border px-3.5 py-1.5 text-sm font-medium"
                    :class="activeCategory === category.id ? 'border-transparent text-white' : 'border-gray-200 text-gray-600 dark:border-gray-700 dark:text-gray-300'"
                    :style="activeCategory === category.id ? { backgroundColor: branding.primary_color } : {}"
                    @click="activeCategory = category.id"
                >
                    {{ category.name }}
                </button>
            </div>
        </div>

        <!-- Menu sections -->
        <main class="mx-auto max-w-2xl px-4 py-6">
            <p v-if="visibleCategories.length === 0" class="mt-10 text-center text-sm text-gray-400">{{ t('menu.no_results') }}</p>

            <section v-for="category in visibleCategories" :key="category.id" class="mb-8">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ category.name }}</h2>
                <p v-if="category.description" class="mt-0.5 text-sm text-gray-500">{{ category.description }}</p>

                <div v-if="category.products.length > 0" class="mt-3 space-y-2">
                    <ProductCard
                        v-for="product in category.products"
                        :key="product.id"
                        :product="product"
                        :currency-symbol="branding.currency_symbol"
                        @add="onAddClick"
                    />
                </div>

                <div v-for="sub in category.subcategories" :key="sub.id" class="mt-5">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ sub.name }}</h3>
                    <div class="mt-2 space-y-2">
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
