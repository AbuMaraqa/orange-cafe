<script setup>
import { computed, reactive, ref, watch } from 'vue';
import CartDrawer from '@/Components/Customer/CartDrawer.vue';
import LanguageSwitcher from '@/Components/LanguageSwitcher.vue';
import NavIcon from '@/Components/NavIcon.vue';
import ProductCard from '@/Components/Customer/ProductCard.vue';
import ProductModal from '@/Components/Customer/ProductModal.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import { useCart } from '@/composables/useCart';
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

// Tracks which category/cover images have failed to load (stale/deleted
// file behind an otherwise-set image_url) so they fall back instead of
// showing the browser's broken-image + alt-text placeholder.
const failedImages = reactive(new Set());

const flatCategories = computed(() => categories.value.map((c) => ({ id: c.id, name: c.name, image_url: c.image_url })));

watch(activeMenuId, () => {
    activeCategory.value = 'all';
    search.value = '';
});

// Forgiving matching for Arabic input: strips diacritics/tatweel and folds
// the letter variants people mix up constantly when typing fast (أ/إ/آ/ا,
// ى/ي, ة/ه) so "أراجيل" and "اراجيل" (or "ارجيله") hit the same product.
function normalize(value) {
    return (value ?? '')
        .toLowerCase()
        .replace(/[\u064B-\u065F\u0670\u0640]/g, '')
        .replace(/[إأآا]/g, 'ا')
        .replace(/ى/g, 'ي')
        .replace(/ة/g, 'ه')
        .replace(/\s+/g, ' ')
        .trim();
}

// Each typed word must appear somewhere in the name/description — order-
// independent, so "تفاح كيك" still finds "كيك التفاح".
function matchesSearch(product) {
    const query = normalize(search.value);
    if (!query) return true;

    const haystack = normalize(`${product.name} ${product.description ?? ''}`);
    return query.split(' ').every((word) => haystack.includes(word));
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

const visibleCategories = computed(() => {
    const source = isSearching.value ? allCategories.value : categories.value;

    return source
        .filter((category) => isSearching.value || activeCategory.value === 'all' || activeCategory.value === category.id)
        .map((category) => ({
            ...category,
            products: filterProducts(category.products),
            subcategories: category.subcategories
                .map((sub) => ({ ...sub, products: filterProducts(sub.products) }))
                .filter((sub) => sub.products.length > 0),
        }))
        .filter((category) => category.products.length > 0 || category.subcategories.length > 0);
});

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
                <div class="flex items-center gap-1.5">
                    <ThemeToggle variant="inverted" />
                    <LanguageSwitcher />
                </div>
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
            <!-- Category carousel: a circular photo per category (Talabat/
                 Careem-style) makes browsing a QR menu feel like flipping
                 through a real menu instead of scanning a wall of text —
                 scroll-snap keeps it smooth to flick through on a phone.
                 A category with no photo yet falls back to the cafe logo,
                 then to an initial-letter avatar in the brand color if
                 there's no logo either, rather than mixing photo and
                 plain-text styles side by side. -->
            <div class="mx-auto mt-3 max-w-lg">
                <div class="flex snap-x snap-mandatory gap-4 overflow-x-auto scroll-smooth pb-1 [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                    <button type="button" class="flex shrink-0 snap-center flex-col items-center gap-1.5" @click="activeCategory = 'all'">
                        <span
                            class="flex h-16 w-16 items-center justify-center rounded-full text-white shadow-sm ring-2 ring-offset-2 transition-all dark:ring-offset-gray-950"
                            :class="activeCategory === 'all' ? 'scale-105 ring-[var(--brand-primary)]' : 'ring-transparent'"
                            :style="{ backgroundColor: branding.primary_color }"
                        >
                            <NavIcon name="grid" class="h-6 w-6" />
                        </span>
                        <span
                            class="line-clamp-2 max-w-[4.75rem] text-center text-xs font-medium leading-tight"
                            :class="activeCategory === 'all' ? 'text-gray-900 dark:text-gray-100' : 'text-gray-500 dark:text-gray-400'"
                        >
                            {{ t('menu.all_categories') }}
                        </span>
                    </button>

                    <button
                        v-for="category in flatCategories"
                        :key="category.id"
                        type="button"
                        class="flex shrink-0 snap-center flex-col items-center gap-1.5"
                        @click="activeCategory = category.id"
                    >
                        <span
                            class="relative flex h-16 w-16 items-center justify-center overflow-hidden rounded-full shadow-sm ring-2 ring-offset-2 transition-all dark:ring-offset-gray-950"
                            :class="activeCategory === category.id ? 'scale-105 ring-[var(--brand-primary)]' : 'ring-transparent'"
                        >
                            <img
                                v-if="category.image_url && !failedImages.has(`category-${category.id}`)"
                                :src="category.image_url"
                                :alt="category.name"
                                class="h-full w-full object-cover"
                                @error="failedImages.add(`category-${category.id}`)"
                            />
                            <div v-else-if="branding.logo_url && !failedImages.has('logo')" class="flex h-full w-full items-center justify-center bg-white p-2 dark:bg-gray-900">
                                <img :src="branding.logo_url" :alt="category.name" class="h-full w-full object-contain opacity-80" @error="failedImages.add('logo')" />
                            </div>
                            <span
                                v-else
                                class="flex h-full w-full items-center justify-center text-lg font-bold text-white"
                                :style="{ backgroundColor: branding.primary_color }"
                            >
                                {{ category.name.charAt(0) }}
                            </span>
                        </span>
                        <span
                            class="line-clamp-2 max-w-[4.75rem] text-center text-xs font-medium leading-tight"
                            :class="activeCategory === category.id ? 'text-gray-900 dark:text-gray-100' : 'text-gray-500 dark:text-gray-400'"
                        >
                            {{ category.name }}
                        </span>
                    </button>
                </div>
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

                <div v-if="category.products.length > 0" class="flex flex-col gap-3">
                    <ProductCard
                        v-for="product in category.products"
                        :key="product.id"
                        :product="product"
                        :currency-symbol="branding.currency_symbol"
                        :logo-url="branding.logo_url"
                        @add="onAddClick"
                    />
                </div>

                <div v-for="sub in category.subcategories" :key="sub.id" class="mt-6">
                    <h3 class="mb-1 text-xs font-semibold uppercase tracking-widest text-gray-400">{{ sub.name }}</h3>
                    <div class="flex flex-col gap-3">
                        <ProductCard
                            v-for="product in sub.products"
                            :key="product.id"
                            :product="product"
                            :currency-symbol="branding.currency_symbol"
                            :logo-url="branding.logo_url"
                            @add="onAddClick"
                        />
                    </div>
                </div>
            </section>
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
                            <img v-if="service.image_url" :src="service.image_url" class="h-full w-full object-cover" :alt="service.name" />
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
