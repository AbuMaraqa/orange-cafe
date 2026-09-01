<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import NavIcon from '@/Components/NavIcon.vue';

const props = defineProps({
    product: {
        type: Object,
        required: true,
    },
    currencySymbol: {
        type: String,
        required: true,
    },
    logoUrl: {
        type: String,
        default: null,
    },
    // The phone menu keeps long names on one line so the card stays a
    // predictable height in a narrow column; the waiter tablet has the room
    // and needs the whole name readable before tapping.
    wrapName: {
        type: Boolean,
        default: false,
    },
    // How many of this dish are already on the order.
    //
    // Zero on the customer menu, which has its own basket in front of the
    // person filling it. The waiter is the one who needs it: they tap the
    // same card several times in a row for a table ordering four of the
    // same thing, and until now the only confirmation was a total ticking
    // up in a panel on the other side of the screen. The search results
    // have shown this count all along — the browse grid is where it was
    // missing, which is the half of the screen used when the waiter does
    // not already know the name of the dish.
    inCart: {
        type: Number,
        default: 0,
    },
});

const emit = defineEmits(['add']);
const { t } = useI18n();

// A product can have image_url set but point at a file that's since been
// deleted/moved — without this, the browser falls back to showing the
// raw alt text where the photo should be instead of the logo tier below.
const imageFailed = ref(false);

function formatPrice(cents) {
    return `${props.currencySymbol}${(cents / 100).toFixed(2)}`;
}
</script>

<template>
    <button
        type="button"
        class="group relative flex w-full items-center gap-3 rounded-2xl bg-white p-3 text-start shadow-sm ring-1 ring-gray-100 transition hover:shadow-md dark:bg-gray-900 dark:ring-gray-800"
        @click="emit('add', product)"
    >
        <!-- DOM order (image first, text second) matters for RTL: a flex
             row in an RTL document lays its first child at the visual
             *start* (right edge), so this puts the thumbnail on the right
             and the text flowing to its left — matching the reference. -->
        <!-- A rounded square rather than a circle. The section tiles above
             are circular because they are navigation — a shape you tap to
             go somewhere. This is the dish itself, and a circular mask
             throws away the corners of every photo: about a quarter of the
             food, taken off the plate before the customer sees it. Square
             keeps it, and keeps the same shape the photo was framed in.
             The clipping stays on an inner element so the featured star can
             sit on the edge rather than be cut off by `overflow-hidden`. -->
        <div class="relative h-24 w-24 shrink-0">
            <div class="h-full w-full overflow-hidden rounded-2xl bg-gray-100 dark:bg-gray-800">
                <!-- Deferred until it is near the viewport, which on this
                     screen is most of them. A section can hold sixty dishes,
                     and without this the phone asks for sixty photos the
                     instant the list renders — at a server that answers one
                     request at a time (`artisan serve` is `php -S`, and its
                     worker pool needs fork(), so on Windows there is exactly
                     one). Those sixty do not merely arrive slowly: they sit
                     in front of the kitchen screen's next request and the
                     waiter tablet's, so one customer opening a menu stalls
                     the floor. Images below the fold are the ones nobody is
                     waiting for, so they are the ones to move out of the way.

                     In-viewport images are unaffected — the browser loads
                     those immediately either way. -->
                <img
                    v-if="product.image_url && !imageFailed"
                    :src="product.image_url"
                    :alt="product.name"
                    loading="lazy"
                    decoding="async"
                    class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                    @error="imageFailed = true"
                />
                <div v-else-if="logoUrl" class="flex h-full w-full items-center justify-center bg-white p-3 dark:bg-gray-900">
                    <img :src="logoUrl" :alt="product.name" class="h-full w-full object-contain opacity-80" />
                </div>
                <div v-else class="flex h-full w-full items-center justify-center text-gray-300 dark:text-gray-700">
                    <NavIcon name="camera" class="h-7 w-7" />
                </div>
            </div>

            <!-- The opposite corner from the star, so a featured dish that
                 is also on the order shows both. On the photo rather than
                 down with the price, because this is the answer to "did
                 that tap land?" and it has to be findable without reading
                 the card. -->
            <span
                v-if="inCart > 0"
                class="absolute -end-1.5 -top-1.5 inline-flex h-6 min-w-6 items-center justify-center rounded-full px-1.5 text-xs font-bold text-white shadow ring-2 ring-white tabular-nums dark:ring-gray-900"
                :style="{ backgroundColor: 'var(--brand-primary)' }"
            >{{ inCart }}</span>

            <!-- On the photo's corner, over the image rather than beside it. -->
            <span
                v-if="product.is_featured"
                class="absolute start-2 top-2 inline-flex items-center rounded-full bg-white/90 p-1 text-amber-500 shadow-sm backdrop-blur"
            >
                <NavIcon name="star" class="h-3 w-3" />
            </span>
        </div>

        <div class="min-w-0 flex-1 py-1">
            <h4
                class="text-base font-semibold leading-snug text-gray-900 dark:text-gray-100"
                :class="wrapName ? 'break-words' : 'truncate'"
            >
                {{ product.name }}
            </h4>
            <p v-if="product.description" class="mt-1 line-clamp-2 text-sm leading-relaxed text-gray-600 dark:text-gray-400">
                {{ product.description }}
            </p>

            <div class="mt-2 flex items-center gap-2">
                <span class="text-base font-bold tabular-nums" style="color: var(--brand-primary)">{{ formatPrice(product.price_cents) }}</span>
                <span v-if="product.modifier_groups?.length" class="text-xs text-gray-500 dark:text-gray-400">{{ t('menu.customizable') }}</span>
                <span v-if="product.prep_time_minutes" class="inline-flex items-center gap-0.5 text-xs text-gray-500 dark:text-gray-400">
                    <NavIcon name="clock" class="h-3 w-3" />
                    {{ t('menu.prep_time', { minutes: product.prep_time_minutes }) }}
                </span>
            </div>

            <div v-if="product.tags?.length" class="mt-1 flex flex-wrap gap-1">
                <span v-for="tag in (product.tags ?? [])" :key="tag.id" class="text-xs text-gray-500 dark:text-gray-400">{{ tag.icon }} {{ tag.name }}</span>
            </div>
        </div>

    </button>
</template>
