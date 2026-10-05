<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import CartDrawer from '@/Components/Customer/CartDrawer.vue';
import LanguageSwitcher from '@/Components/LanguageSwitcher.vue';
import NavIcon from '@/Components/NavIcon.vue';
import CategoryTile from '@/Components/Customer/CategoryTile.vue';
import MenuSection from '@/Components/Customer/MenuSection.vue';
import ProductCard from '@/Components/Customer/ProductCard.vue';
import ProductModal from '@/Components/Customer/ProductModal.vue';
import { useCart } from '@/composables/useCart';
import { matchesQuery } from '@/composables/useProductSearch';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    menus: {
        type: Array,
        required: true,
    },
    services: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const { t } = useI18n();
const cart = useCart();

// The cart is restored from localStorage with the prices it was saved
// with, and it is saved indefinitely. Checking it against the menu this
// page was just rendered with is the only thing that stops a tablet left
// on the menu — or a customer coming back the next day — from being shown
// last week's prices for an order the server will charge today's prices
// for.
const staleCart = reactive({ removed: [], repriced: [] });

onMounted(() => {
    const changed = cart.reconcile(props.menus);

    staleCart.removed = changed.removed;
    staleCart.repriced = changed.repriced;
});

const cartWasCorrected = computed(() => staleCart.removed.length > 0 || staleCart.repriced.length > 0);

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
const selectedProduct = ref(null);
const showCart = ref(false);

// Tracks which category/cover images have failed to load (stale/deleted
// file behind an otherwise-set image_url) so they fall back instead of
// showing the browser's broken-image + alt-text placeholder.
const failedImages = reactive(new Set());

/* ------------------------------------------------------------------ *
 * Browsing the menu
 * ------------------------------------------------------------------ */

// The menu is browsed by opening sections, not by scrolling past them. A
// café with a hundred and fifty items in one column is a wall of text, and
// a customer at a table with friends will not read it — they will ask the
// waiter, which is the thing the QR menu exists to avoid.
//
// `path` is the trail of section ids from the top down to wherever the
// customer currently is. Depth is whatever the data says: مشروبات ←
// ساخنة ← قهوة ← مختصة all work, because nothing here counts levels.
const path = ref([]);

// Each section along the trail, resolved from the ids. Any id that no
// longer exists (a section deleted while the page was open) truncates the
// trail there rather than throwing.
const trail = computed(() => {
    const out = [];
    let level = categories.value;

    for (const id of path.value) {
        const found = level.find((category) => category.id === id);
        if (!found) break;

        out.push(found);
        level = found.subcategories ?? [];
    }

    return out;
});

const currentCategory = computed(() => trail.value[trail.value.length - 1] ?? null);

// At the top the tiles are the menu's own sections; inside one, they are
// its sub-sections.
const currentSections = computed(() =>
    currentCategory.value ? (currentCategory.value.subcategories ?? []) : categories.value
);

// A section can hold dishes and sub-sections at the same time — a house
// special sitting next to "hot" and "cold" is a real menu, not an edge case.
const currentProducts = computed(() => {
    const products = currentCategory.value?.products ?? [];

    return isSearching.value ? filterProducts(products) : products;
});

function openCategory(category) {
    path.value = [...path.value, category.id];
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// index 0 is the menu root, 1 is the first section, and so on.
function goToLevel(index) {
    path.value = path.value.slice(0, index);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function goBack() {
    goToLevel(path.value.length - 1);
}

// Switching menu tabs starts a fresh trail — the old one points at sections
// that belong to a different menu.
watch(activeMenuId, () => {
    path.value = [];
    search.value = '';
});

// Forgiving matching for Arabic input: strips diacritics/tatweel and folds
// the letter variants people mix up constantly when typing fast (أ/إ/آ/ا,
// ى/ي, ة/ه) so "أراجيل" and "اراجيل" (or "ارجيله") hit the same product.
// Lives in useProductSearch now, shared with the staff order screen. That
// screen had its own weaker copy — a plain substring match with none of the
// folding — so a waiter typing a name with an alef hamza got nothing while a
// customer typing the same thing found it.
function matchesSearch(product) {
    return matchesQuery(product, search.value);
}

function filterProducts(products) {
    return products.filter(matchesSearch);
}

// A customer searching rarely knows (or cares) which menu tab an item
// lives under — "شيشة" typed while the "Food" menu is active should still
// find items under the "Shisha" menu, so a search searches every menu at
// once and ignores both the menu switcher and the category filter.
const isSearching = computed(() => search.value.trim().length > 0);
const allCategories = computed(() => props.menus.flatMap((m) => m.categories));

/**
 * Filters a section and everything under it, dropping any branch that ends
 * up empty. Recursive because the menu nests to whatever depth the cafe set
 * up — a search that only looked two levels down silently hid matches that
 * sat deeper.
 */
function pruneCategory(category) {
    const products = filterProducts(category.products ?? []);
    const subcategories = (category.subcategories ?? []).map(pruneCategory).filter(Boolean);

    if (products.length === 0 && subcategories.length === 0) {
        return null;
    }

    return { ...category, products, subcategories };
}

// Search results are a flat list of everything that matched, across every
// menu and every depth — a customer looking for "شاي" does not know or care
// which section it lives in.
const visibleCategories = computed(() =>
    allCategories.value.map(pruneCategory).filter(Boolean)
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

// Admins may enter either a full profile URL or just a handle (the field
// has no fixed format) — accept both instead of forcing one convention.
function socialUrl(platform, value) {
    if (!value) return null;
    if (/^https?:\/\//i.test(value)) return value;
    const handle = value.replace(/^@/, '');
    return `https://${platform}.com/${handle}`;
}

function whatsappUrl(value) {
    if (!value) return null;
    if (/^https?:\/\//i.test(value)) return value;
    return `https://wa.me/${value.replace(/[^\d]/g, '')}`;
}

const contactLinks = computed(() => [
    branding.value.phone && { key: 'phone', href: `tel:${branding.value.phone}`, icon: 'phone', filled: false },
    branding.value.whatsapp && { key: 'whatsapp', href: whatsappUrl(branding.value.whatsapp), icon: 'whatsapp', filled: true },
    branding.value.instagram && { key: 'instagram', href: socialUrl('instagram', branding.value.instagram), icon: 'instagram', filled: true },
    branding.value.facebook && { key: 'facebook', href: socialUrl('facebook', branding.value.facebook), icon: 'facebook', filled: true },
].filter(Boolean));

// Lets a customer flag their table without placing an order first — pick
// from the admin-managed list of services (e.g. Call Waiter, Bring the
// Bill, Extra Chair) rather than one hardcoded action. Each service gets
// its own short cooldown after being requested so an impatient tap
// doesn't spam the floor, tracked independently per service so
// requesting one doesn't lock out the others.
const showServicesSheet = ref(false);
const requestingServiceId = ref(null);
const requestedServiceIds = reactive(new Set());

function requestService(service) {
    if (requestingServiceId.value !== null || requestedServiceIds.has(service.id)) return;
    requestingServiceId.value = service.id;

    router.post(route('waiter-calls.store'), {
        table_id: tableId,
        table_number: tableId ? null : tableLabel,
        service_id: service.id,
    }, {
        preserveScroll: true,
        onFinish: () => { requestingServiceId.value = null; },
        onSuccess: () => {
            requestedServiceIds.add(service.id);
            setTimeout(() => requestedServiceIds.delete(service.id), 60000);
        },
    });
}

// General feedback about the cafe (not tied to an order) — a customer can
// send this any time from the menu, whether or not they've ordered.
const showFeedbackSheet = ref(false);
const feedbackSent = ref(false);
const feedbackForm = useForm({ message: '' });

function submitFeedback() {
    if (!feedbackForm.message.trim()) return;

    feedbackForm.transform((data) => ({
        ...data,
        table_id: tableId,
        table_number: tableId ? null : tableLabel,
    })).post(route('feedback.store'), {
        preserveScroll: true,
        onSuccess: () => {
            feedbackForm.reset();
            feedbackSent.value = true;
            setTimeout(() => {
                showFeedbackSheet.value = false;
                feedbackSent.value = false;
            }, 1800);
        },
    });
}
</script>

<template>
    <Head :title="t('menu.title')" />

    <div class="min-h-screen bg-white pb-28 dark:bg-gray-950">
        <!-- The cart was corrected against the live menu. Said out loud
             rather than done silently: a total that changes between one
             visit and the next, with no explanation, reads as the menu
             overcharging. -->
        <div
            v-if="cartWasCorrected"
            class="flex items-start gap-3 border-b border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900 dark:bg-amber-950"
        >
            <NavIcon name="bell" class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" />
            <div class="flex-1 text-sm text-amber-900 dark:text-amber-100">
                <p v-if="staleCart.repriced.length" class="font-medium">
                    {{ t('menu.cart_repriced', { items: staleCart.repriced.join('، ') }) }}
                </p>
                <p v-if="staleCart.removed.length" class="font-medium">
                    {{ t('menu.cart_items_gone', { items: staleCart.removed.join('، ') }) }}
                </p>
            </div>
            <button type="button" class="shrink-0 text-amber-500" @click="staleCart.removed = []; staleCart.repriced = []">
                <NavIcon name="close" class="h-4 w-4" />
            </button>
        </div>

        <!-- Hero -->
        <div class="relative h-64 w-full overflow-hidden sm:h-72">
            <img
                v-if="branding.cover_image_url && !failedImages.has('cover')"
                :src="branding.cover_image_url"
                class="absolute inset-0 h-full w-full object-cover"
                alt=""
                @error="failedImages.add('cover')"
            />
            <div v-else class="absolute inset-0" :style="{ backgroundColor: branding.primary_color }"></div>
            <div class="absolute inset-0 bg-gradient-to-t from-black/85 via-black/35 to-black/10"></div>

            <div class="absolute inset-x-0 top-0 flex items-start justify-between p-4">
                <span
                    v-if="tableLabel"
                    class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1 text-xs font-medium text-white backdrop-blur-sm"
                >
                    {{ t('tables.title') }} {{ tableLabel }}
                </span>
                <!-- No theme toggle: this page is light, always. A customer
                     is not choosing an interface, they are reading a menu,
                     and the control was one more thing on a screen that
                     wants as few as possible. -->
                <LanguageSwitcher />
            </div>

            <div class="absolute inset-x-0 bottom-0 flex flex-col items-center px-6 pb-7 text-center text-white">
                <div v-if="branding.logo_url && !failedImages.has('logo')" class="mb-3 h-20 w-20 overflow-hidden rounded-full bg-white/10 shadow-xl ring-4 ring-white/25 backdrop-blur">
                    <img :src="branding.logo_url" class="h-full w-full object-cover" @error="failedImages.add('logo')" />
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
        </div>
        <main class="mx-auto max-w-lg px-5 py-5">
            <!-- Searching replaces browsing rather than filtering it: a
                 customer who types has already told us what they want, and
                 making them also be in the right section to find it is the
                 kind of thing that sends them back to asking the waiter. -->
            <template v-if="isSearching">
                <p v-if="visibleCategories.length === 0" class="mt-10 text-center text-sm text-gray-400">{{ t('menu.no_results') }}</p>

                <MenuSection
                    v-for="category in visibleCategories"
                    :key="category.id"
                    :category="category"
                    :branding="branding"
                    @add="onAddClick"
                />
            </template>

            <template v-else>
                <!-- Where you are, and one tap back to anywhere above it.
                     A drill-down without a visible way out is a trap on a
                     phone, where the browser's back button leaves the menu
                     altogether. -->
                <!-- Pinned to the top of the screen while the section is
                     scrolled.
                     A long section pushes the way out off the screen, and on
                     a phone the only remaining exit is the browser's back
                     button — which leaves the menu entirely and loses the
                     table. Keeping the trail in view means the way back is
                     one tap from anywhere in a section, however far down the
                     guest has read.
                     The blur and the background matter as much as the
                     position: dish names scrolling under a transparent bar
                     read as part of it. -->
                <div
                    v-if="trail.length > 0"
                    class="sticky top-0 z-30 -mx-4 mb-5 flex items-center gap-2 border-b border-gray-100 bg-white/90 px-4 py-2.5 backdrop-blur-md sm:-mx-6 sm:px-6"
                >
                    <button
                        type="button"
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-gray-200 text-gray-500 transition-colors hover:bg-gray-50 hover:text-gray-900 dark:border-gray-800 dark:text-gray-400 dark:hover:bg-gray-900"
                        :aria-label="t('common.back')"
                        @click="goBack"
                    >
                        <NavIcon name="chevronLeft" class="h-4 w-4 rtl:rotate-180" />
                    </button>

                    <nav class="flex min-w-0 flex-wrap items-center gap-1 text-xs">
                        <button
                            type="button"
                            class="shrink-0 text-gray-400 transition-colors hover:text-gray-700 dark:hover:text-gray-200"
                            @click="goToLevel(0)"
                        >
                            {{ t('menu.all_categories') }}
                        </button>
                        <template v-for="(step, index) in trail" :key="step.id">
                            <span class="text-gray-300">›</span>
                            <button
                                type="button"
                                class="max-w-[9rem] truncate transition-colors"
                                :class="index === trail.length - 1
                                    ? 'font-semibold text-gray-900 dark:text-gray-100'
                                    : 'text-gray-400 hover:text-gray-700 dark:hover:text-gray-200'"
                                @click="goToLevel(index + 1)"
                            >
                                {{ step.name }}
                            </button>
                        </template>
                    </nav>
                </div>

                <!-- Guests who have never ordered from a QR menu read it as
                     a picture of the paper one and wait for the waiter. Said
                     once, above the sections, before they start browsing.
                     Only with a table in the URL: without one the cart
                     cannot send, and the card would promise what it can't. -->
                <div
                    v-if="tableId && trail.length === 0"
                    class="relative mb-6 overflow-hidden rounded-2xl border border-gray-100 p-4 dark:border-gray-800"
                >
                    <span class="absolute inset-0" :style="{ backgroundColor: branding.primary_color, opacity: 0.06 }"></span>

                    <div class="relative flex items-start gap-3">
                        <span
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-white shadow-sm"
                            :style="{ backgroundColor: branding.primary_color }"
                        >
                            <NavIcon name="smartphone" class="h-5 w-5" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[15px] font-bold text-gray-900 dark:text-gray-100">{{ t('menu.order_here_title') }}</p>
                            <p class="mt-0.5 text-xs leading-relaxed text-gray-500 dark:text-gray-400">{{ t('menu.order_here_subtitle') }}</p>
                        </div>
                    </div>

                    <ol class="relative mt-4 grid grid-cols-3 gap-2">
                        <li
                            v-for="(step, index) in ['menu.order_step_pick', 'menu.order_step_add', 'menu.order_step_send']"
                            :key="step"
                            class="flex flex-col items-center gap-1.5 rounded-xl bg-white/80 px-1 py-2.5 text-center dark:bg-gray-900/80"
                        >
                            <span
                                class="flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold text-white"
                                :style="{ backgroundColor: branding.primary_color }"
                            >
                                {{ index + 1 }}
                            </span>
                            <span class="text-xs font-semibold leading-tight text-gray-800 dark:text-gray-200">{{ t(step) }}</span>
                        </li>
                    </ol>
                </div>

                <div v-if="currentCategory" class="mb-5">
                    <h2 class="text-xl font-bold tracking-tight text-gray-900 dark:text-gray-100">{{ currentCategory.name }}</h2>
                    <p v-if="currentCategory.description" class="mt-1 text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                        {{ currentCategory.description }}
                    </p>
                </div>

                <!-- The sections available from here. Round photo tiles, the
                     way a paper menu is browsed: look, open, and what is
                     inside is only what belongs there. -->
                <div v-if="currentSections.length > 0" class="grid grid-cols-3 gap-x-3 gap-y-5 sm:grid-cols-4">
                    <CategoryTile
                        v-for="section in currentSections"
                        :key="section.id"
                        :category="section"
                        :branding="branding"
                        :failed-images="failedImages"
                        @open="openCategory"
                    />
                </div>

                <!-- A section's own dishes, which sit alongside its
                     sub-sections rather than instead of them. -->
                <div v-if="currentProducts.length > 0" :class="currentSections.length > 0 ? 'mt-8' : ''">
                    <div v-if="currentSections.length > 0" class="mb-3 flex items-center gap-3">
                        <h3 class="shrink-0 text-sm font-semibold uppercase tracking-widest text-gray-500 dark:text-gray-400">
                            {{ t('menu.also_here') }}
                        </h3>
                        <span class="h-px flex-1" :style="{ backgroundColor: branding.primary_color, opacity: 0.25 }"></span>
                    </div>

                    <div class="flex flex-col gap-3">
                        <ProductCard
                            v-for="product in currentProducts"
                            :key="product.id"
                            :product="product"
                            :currency-symbol="branding.currency_symbol"
                            :logo-url="branding.logo_url"
                            @add="onAddClick"
                        />
                    </div>
                </div>

                <p
                    v-if="currentSections.length === 0 && currentProducts.length === 0"
                    class="mt-12 text-center text-sm text-gray-400"
                >
                    {{ t('menu.empty') }}
                </p>
            </template>
        </main>

        <footer v-if="contactLinks.length > 0" class="border-t border-gray-100 px-5 py-8 text-center dark:border-gray-800">
            <p class="mb-3 text-xs font-medium uppercase tracking-widest text-gray-400">{{ t('menu.get_in_touch') }}</p>
            <div class="flex items-center justify-center gap-3">
                <a
                    v-for="link in contactLinks"
                    :key="link.key"
                    :href="link.href"
                    target="_blank"
                    rel="noopener"
                    class="flex h-11 w-11 items-center justify-center rounded-full bg-gray-100 text-gray-600 transition hover:opacity-80 dark:bg-gray-800 dark:text-gray-300"
                >
                    <NavIcon :name="link.icon" :filled="link.filled" class="h-5 w-5" />
                </a>
            </div>
            <p v-if="branding.address" class="mx-auto mt-4 max-w-xs text-xs text-gray-400">{{ branding.address }}</p>
        </footer>

        <!-- Feedback: not gated behind contact links being configured — a
             customer should always be able to leave a note, order or not. -->
        <div class="border-t border-gray-100 px-5 py-6 text-center dark:border-gray-800">
            <button
                type="button"
                class="inline-flex items-center gap-1.5 text-sm font-medium text-gray-500 underline hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200"
                @click="showFeedbackSheet = true"
            >
                <NavIcon name="note" class="h-4 w-4" />
                {{ t('menu.add_feedback') }}
            </button>
        </div>

        <!-- Services FAB: raised well above the small header pill it used to
             be — a customer flagging staff down is a "notice me" action, so
             it gets the same visual weight as the cart button. It floats
             above the cart bar (rather than beside it) once the cart has
             items, so the two never collide on a narrow phone screen. Opens
             a sheet listing every admin-configured service instead of
             firing one hardcoded action. -->
        <div
            v-if="(tableId || tableLabel) && services.length > 0"
            class="fixed end-4 z-40 transition-all duration-300"
            :class="cart.count.value > 0 ? 'bottom-24' : 'bottom-4'"
        >
            <span
                v-if="!showServicesSheet"
                class="absolute inset-0 animate-ping rounded-full opacity-40"
                style="background-color: #f59e0b"
            ></span>
            <button
                type="button"
                class="relative flex items-center gap-2 rounded-full px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-black/25 transition"
                style="background-color: #f59e0b"
                @click="showServicesSheet = true"
            >
                <NavIcon name="bell" class="h-5 w-5" />
                {{ t('menu.call_waiter') }}
            </button>
        </div>

        <!-- Services sheet: one tap per request, each button shows its own
             sent/cooldown state independently so requesting one service
             doesn't visually lock the others. -->
        <div
            v-if="showServicesSheet"
            class="fixed inset-0 z-50 flex items-end justify-center bg-gray-900/50 sm:items-center"
            @click.self="showServicesSheet = false"
        >
            <div class="max-h-[80vh] w-full max-w-md overflow-y-auto rounded-t-2xl bg-white p-5 sm:rounded-2xl dark:bg-gray-900">
                <div class="mb-1 flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ t('menu.services_title') }}</h3>
                        <p class="mt-0.5 text-sm text-gray-500">{{ t('menu.services_subtitle') }}</p>
                    </div>
                    <button type="button" class="shrink-0 text-gray-400 hover:text-gray-600" @click="showServicesSheet = false">
                        <NavIcon name="close" class="h-5 w-5" />
                    </button>
                </div>

                <div class="mt-4 space-y-2">
                    <button
                        v-for="service in services"
                        :key="service.id"
                        type="button"
                        class="flex w-full items-center gap-3 rounded-xl border p-3.5 text-start transition disabled:cursor-default"
                        :class="requestedServiceIds.has(service.id)
                            ? 'border-teal-400 bg-teal-50 dark:border-teal-700 dark:bg-teal-900/20'
                            : 'border-gray-200 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800'"
                        :disabled="requestingServiceId !== null || requestedServiceIds.has(service.id)"
                        @click="requestService(service)"
                    >
                        <span
                            class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full text-xl text-white"
                            :style="{ backgroundColor: service.color }"
                        >
                            <!-- Inside a sheet that is closed until the
                                 customer asks for it, so these are requests
                                 for pictures nobody has looked at yet. -->
                            <img v-if="service.image_url" :src="service.image_url" loading="lazy" decoding="async" class="h-full w-full object-cover" :alt="service.name" />
                            <template v-else>{{ service.icon }}</template>
                        </span>
                        <span class="flex-1 text-sm font-medium text-gray-900 dark:text-gray-100">{{ service.name }}</span>
                        <span v-if="requestedServiceIds.has(service.id)" class="flex shrink-0 items-center gap-1 text-xs font-medium text-teal-600">
                            <NavIcon name="check" class="h-4 w-4" />{{ t('menu.service_requested') }}
                        </span>
                        <span v-else-if="requestingServiceId === service.id" class="text-xs text-gray-400">…</span>
                    </button>
                </div>
            </div>
        </div>

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

        <!-- Feedback sheet -->
        <div
            v-if="showFeedbackSheet"
            class="fixed inset-0 z-50 flex items-end justify-center bg-gray-900/50 sm:items-center"
            @click.self="showFeedbackSheet = false"
        >
            <div class="max-h-[80vh] w-full max-w-md overflow-y-auto rounded-t-2xl bg-white p-5 sm:rounded-2xl dark:bg-gray-900">
                <div class="mb-1 flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ t('menu.feedback_title') }}</h3>
                        <p class="mt-0.5 text-sm text-gray-500">{{ t('menu.feedback_subtitle') }}</p>
                    </div>
                    <button type="button" class="shrink-0 text-gray-400 hover:text-gray-600" @click="showFeedbackSheet = false">
                        <NavIcon name="close" class="h-5 w-5" />
                    </button>
                </div>

                <div v-if="feedbackSent" class="flex flex-col items-center py-8 text-center">
                    <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-full text-white" style="background-color: var(--brand-primary)">
                        <NavIcon name="check" class="h-6 w-6" />
                    </div>
                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ t('menu.feedback_sent') }}</p>
                </div>
                <form v-else class="mt-4" @submit.prevent="submitFeedback">
                    <textarea
                        v-model="feedbackForm.message"
                        rows="4"
                        class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-500 focus:ring-gray-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                        :placeholder="t('menu.feedback_placeholder')"
                    ></textarea>
                    <p v-if="feedbackForm.errors.message" class="mt-1 text-xs text-red-500">{{ feedbackForm.errors.message }}</p>
                    <button
                        type="submit"
                        class="mt-3 w-full rounded-md px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-40"
                        style="background-color: var(--brand-primary)"
                        :disabled="feedbackForm.processing || !feedbackForm.message.trim()"
                    >
                        {{ t('menu.feedback_submit') }}
                    </button>
                </form>
            </div>
        </div>

        <ProductModal
            v-if="selectedProduct"
            :product="selectedProduct"
            :currency-symbol="branding.currency_symbol"
            :logo-url="branding.logo_url"
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
