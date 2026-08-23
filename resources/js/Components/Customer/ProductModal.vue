<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import NavIcon from '@/Components/NavIcon.vue';
import { unitPriceCents } from '@/composables/useProductPricing';

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
});

const emit = defineEmits(['close', 'add']);
const { t } = useI18n();

// See ProductCard.vue — a stale/broken image_path shouldn't render as a
// raw alt-text placeholder where the logo fallback belongs.
const imageFailed = ref(false);
watch(() => props.product, () => { imageFailed.value = false; });

// { [groupId]: number[] } — selected modifier ids per group.
const selections = reactive({});
const quantity = ref(1);
const notes = ref('');

watch(
    () => props.product,
    (product) => {
        quantity.value = 1;
        notes.value = '';
        Object.keys(selections).forEach((key) => delete selections[key]);
        product.modifier_groups.forEach((group) => {
            selections[group.id] = [];
        });
    },
    { immediate: true },
);

function toggleModifier(group, modifier) {
    const current = selections[group.id] ?? [];
    const isSingle = group.max_select === 1;

    if (current.includes(modifier.id)) {
        selections[group.id] = current.filter((id) => id !== modifier.id);
        return;
    }

    if (isSingle) {
        selections[group.id] = [modifier.id];
        return;
    }

    if (group.max_select && current.length >= group.max_select) {
        return;
    }

    selections[group.id] = [...current, modifier.id];
}

function isSelected(group, modifier) {
    return (selections[group.id] ?? []).includes(modifier.id);
}

const canSubmit = computed(() =>
    props.product.modifier_groups.every((group) => {
        const count = (selections[group.id] ?? []).length;
        const min = group.is_required ? Math.max(group.min_select, 1) : group.min_select;
        return count >= min;
    }),
);

/** Every modifier the customer has ticked, flattened across the groups. */
const chosenModifiers = computed(() =>
    props.product.modifier_groups.flatMap((group) => {
        const chosen = selections[group.id] ?? [];

        return group.modifiers.filter((m) => chosen.includes(m.id));
    })
);

// useProductPricing owns the rule, shared with the cart and the server: a
// size replaces the price, an extra adds to it. Adding them all to the
// base here is what showed a sized burger at base + size.
const totalCents = computed(() => unitPriceCents(props.product, chosenModifiers.value) * quantity.value);

function formatPrice(cents) {
    return `${props.currencySymbol}${(cents / 100).toFixed(2)}`;
}

function confirm() {
    if (!canSubmit.value) return;

    const selectedModifiers = props.product.modifier_groups.flatMap((group) =>
        group.modifiers.filter((m) => (selections[group.id] ?? []).includes(m.id)),
    );

    emit('add', { selectedModifiers, quantity: quantity.value, notes: notes.value });
}
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-end justify-center bg-gray-900/50 sm:items-center" @click.self="emit('close')">
        <div class="max-h-[85vh] w-full max-w-md overflow-y-auto rounded-t-2xl bg-white sm:rounded-2xl dark:bg-gray-900">
            <div v-if="product.image_url && !imageFailed" class="h-52 w-full overflow-hidden">
                <img :src="product.image_url" :alt="product.name" class="h-full w-full object-cover" @error="imageFailed = true" />
            </div>
            <div v-else-if="logoUrl" class="flex h-24 w-full items-center justify-center bg-gray-50 p-4 dark:bg-gray-800">
                <img :src="logoUrl" :alt="product.name" class="h-full max-w-[40%] object-contain opacity-80" />
            </div>
            <div v-else class="flex h-24 w-full items-center justify-center bg-gray-100 text-gray-300 dark:bg-gray-800 dark:text-gray-700">
                <NavIcon name="camera" class="h-10 w-10" />
            </div>

            <div class="p-5">
                <div class="flex items-start justify-between gap-3">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                        <NavIcon v-if="product.is_featured" name="star" class="me-1 -mt-0.5 inline h-4 w-4 text-amber-500" />{{ product.name }}
                    </h3>
                    <button type="button" class="shrink-0 text-gray-400 hover:text-gray-600" @click="emit('close')">
                        <NavIcon name="close" class="h-5 w-5" />
                    </button>
                </div>
                <div class="mt-1 flex items-center gap-2">
                    <p class="font-semibold" style="color: var(--brand-primary)">{{ formatPrice(product.price_cents) }}</p>
                    <span v-if="product.prep_time_minutes" class="inline-flex items-center gap-0.5 text-xs text-gray-400">
                        <NavIcon name="clock" class="h-3.5 w-3.5" />
                        {{ t('menu.prep_time', { minutes: product.prep_time_minutes }) }}
                    </span>
                </div>
                <p v-if="product.description" class="mt-1 text-sm text-gray-500">{{ product.description }}</p>

                <div v-if="product.tags.length" class="mt-2 flex flex-wrap gap-2">
                    <span
                        v-for="tag in product.tags"
                        :key="tag.id"
                        class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300"
                    >{{ tag.icon }} {{ tag.name }}</span>
                </div>

                <div v-for="group in product.modifier_groups" :key="group.id" class="mt-5">
                    <div class="mb-2 flex items-center justify-between">
                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ group.name }}</p>
                        <span v-if="group.is_required" class="text-xs text-amber-600">{{ t('menu.required') }}</span>
                    </div>
                    <div class="space-y-1.5">
                        <label
                            v-for="modifier in group.modifiers"
                            :key="modifier.id"
                            class="flex cursor-pointer items-center justify-between rounded-md border px-3 py-2 text-sm"
                            :class="isSelected(group, modifier)
                                ? 'border-gray-900 bg-gray-50 dark:border-gray-100 dark:bg-gray-800'
                                : 'border-gray-200 dark:border-gray-700'"
                            @click="toggleModifier(group, modifier)"
                        >
                            <span class="text-gray-700 dark:text-gray-300">{{ modifier.name }}</span>
                            <!-- A size *is* the price, so it is written as a
                                 price. Only an extra is a surcharge, and only
                                 an extra gets the plus. Showing "+30" beside a
                                 size told the customer it would be added to
                                 the 30 already on the card — which is exactly
                                 what the basket then did. -->
                            <span class="text-gray-500">
                                {{ group.is_variation
                                    ? formatPrice(modifier.price_cents)
                                    : (modifier.price_cents > 0 ? `+${formatPrice(modifier.price_cents)}` : '') }}
                            </span>
                        </label>
                    </div>
                </div>

                <div class="mt-5">
                    <label class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ t('menu.notes') }}</label>
                    <textarea
                        v-model="notes"
                        rows="2"
                        class="mt-1.5 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-500 focus:ring-gray-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                        :placeholder="t('menu.notes_placeholder')"
                    ></textarea>
                </div>

                <div class="mt-6 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <button
                            type="button"
                            class="flex h-8 w-8 items-center justify-center rounded-full border border-gray-300 text-gray-600 dark:border-gray-600 dark:text-gray-300"
                            @click="quantity = Math.max(1, quantity - 1)"
                        >−</button>
                        <span class="w-4 text-center font-medium">{{ quantity }}</span>
                        <button
                            type="button"
                            class="flex h-8 w-8 items-center justify-center rounded-full border border-gray-300 text-gray-600 dark:border-gray-600 dark:text-gray-300"
                            @click="quantity++"
                        >+</button>
                    </div>

                    <button
                        type="button"
                        class="rounded-md px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-40"
                        style="background-color: var(--brand-primary)"
                        :disabled="!canSubmit"
                        @click="confirm"
                    >
                        {{ t('menu.add_to_cart') }} · {{ formatPrice(totalCents) }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
