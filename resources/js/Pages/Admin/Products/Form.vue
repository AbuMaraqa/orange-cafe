<script setup>
import { computed, reactive, ref, watch } from 'vue';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { slugify } from '@/slug';

const props = defineProps({
    languages: {
        type: Array,
        required: true,
    },
    modifierGroups: {
        type: Array,
        default: () => [],
    },
    tags: {
        type: Array,
        default: () => [],
    },
    categories: {
        type: Array,
        default: () => [],
    },
    printerOptions: {
        type: Array,
        default: () => [],
    },
    product: {
        type: Object,
        default: null,
    },
});

const isEdit = computed(() => props.product !== null);

const emptyTranslations = Object.fromEntries(
    props.languages.map((l) => [l.code, { name: '', description: '' }]),
);

const form = useForm({
    slug: props.product?.slug ?? '',
    station: props.product?.station ?? '',
    image: null,
    price_cents: props.product?.price_cents ?? 0,
    prep_time_minutes: props.product?.prep_time_minutes ?? '',
    category_ids: props.product?.category_ids ?? [],
    is_active: props.product?.is_active ?? true,
    sort_order: props.product?.sort_order ?? 0,
    modifier_group_ids: props.product?.modifier_group_ids ?? [],
    tag_ids: props.product?.tag_ids ?? [],
    translations: props.product?.translations ?? emptyTranslations,
});

// The form holds cents (what the backend expects) but an admin types
// currency units, so the two are kept apart.
//
// They used to be one computed with `.toFixed(2)` in its getter, which
// rewrote the box on every keystroke: typing "12.50" went 1 → "1.00",
// then "1.002" → "1.00", and the price could not be typed at all. The
// text now stays exactly as typed and is only tidied up on the way out.
const priceInput = ref(props.product ? (props.product.price_cents / 100).toFixed(2) : '');

watch(priceInput, (value) => {
    const cents = Math.round(Number(value) * 100);
    form.price_cents = value !== '' && Number.isFinite(cents) && cents >= 0 ? cents : 0;
});

function normalisePrice() {
    priceInput.value = (form.price_cents / 100).toFixed(2);
}

const imagePreview = ref(props.product?.image_url ?? null);

// Per-product overrides for shared modifiers — keyed by modifier id, kept
// separate from `form` and folded in via form.transform() at submit time
// so most modifiers (which never get a custom price) never enter the
// payload at all. null means "inherit the modifier's global value".
const overrides = reactive(
    Object.fromEntries(
        props.modifierGroups.flatMap((group) => group.modifiers.map((modifier) => {
            const existing = props.product?.modifier_overrides?.[modifier.id];
            return [modifier.id, {
                price_cents: existing?.price_cents ?? null,
                // The text as typed. Held separately for the same reason
                // priceInput above is: deriving the box's value from
                // price_cents rewrote it on every keystroke, so "2.50"
                // turned into "2.00" and then "2.01" as the caret was
                // thrown to the end mid-number. Empty means "inherit".
                price_input: existing?.price_cents == null ? '' : (existing.price_cents / 100).toFixed(2),
                is_active: existing?.is_active ?? null,
            }];
        })),
    ),
);

function setOverridePrice(modifierId, value) {
    overrides[modifierId].price_input = value ?? '';

    if (value === '' || value === null || value === undefined) {
        overrides[modifierId].price_cents = null;
        return;
    }

    const cents = Math.round(Number(value) * 100);
    overrides[modifierId].price_cents = Number.isFinite(cents) && cents >= 0 ? cents : null;
}

// Tidying happens on the way out of the field, never while typing. A blank
// box stays blank: that is "inherit the group's price", not zero.
function normaliseOverridePrice(modifierId) {
    const override = overrides[modifierId];

    override.price_input = override.price_cents === null ? '' : (override.price_cents / 100).toFixed(2);
}

function toggleOverrideAvailable(modifierId, checked) {
    // Re-checking goes back to "inherit" (null) rather than storing an
    // explicit true — keeps the overrides table free of no-op rows.
    overrides[modifierId].is_active = checked ? null : false;
}

form.transform((data) => ({
    ...data,
    modifier_overrides: Object.entries(overrides)
        .filter(([, o]) => o.price_cents !== null || o.is_active !== null)
        // price_input is display state only; the request wants cents.
        .map(([modifierId, o]) => ({
            modifier_id: Number(modifierId),
            price_cents: o.price_cents,
            is_active: o.is_active,
        })),
}));

// Where the product sits in the menu. This was add-only — the categories
// it was already in showed as flat chips and the checklist below excluded
// them, so a product filed under the wrong section could never be moved,
// only duplicated into a second one. It is one editable set now.
//
// Unchecking a category the product is currently in does drop that
// placement's own settings (its order within the section, its featured
// flag, any per-section price), which is why the form says so rather than
// doing it quietly.
const originalCategoryIds = new Set(props.product?.category_ids ?? []);

function toggleCategory(categoryId, checked) {
    form.category_ids = checked
        ? [...form.category_ids, categoryId]
        : form.category_ids.filter((id) => id !== categoryId);
}

const removedCategories = computed(() =>
    props.categories.filter((c) => originalCategoryIds.has(c.id) && !form.category_ids.includes(c.id))
);
const defaultLocaleCode = props.languages.find((l) => l.is_default)?.code ?? props.languages[0]?.code;
const activeTab = ref(defaultLocaleCode);

// Typing the name auto-fills the slug, so there's no separate field to
// think about up front — stops the moment the admin edits the slug
// themselves (an existing product's slug is also left alone, since it
// may already be linked elsewhere).
//
// An Arabic name leaves the box empty rather than filling it with
// Arabic: slugify() only speaks ASCII on purpose, and the server
// transliterates the name into a real slug on save (GeneratesSlug).
// The placeholder on the field says so, so an empty box does not read
// as something forgotten.
const slugEditedManually = ref(isEdit.value);

watch(() => form.translations[defaultLocaleCode]?.name, (name) => {
    if (!slugEditedManually.value) {
        form.slug = slugify(name);
    }
});

function onSlugInput() {
    slugEditedManually.value = true;
}

function onImageChange(event) {
    const file = event.target.files[0];
    form.image = file ?? null;
    imagePreview.value = file ? URL.createObjectURL(file) : (props.product?.image_url ?? null);
}

function submit() {
    if (isEdit.value) {
        form.put(route('admin.products.update', props.product.id));
    } else {
        form.post(route('admin.products.store'));
    }
}
</script>

<template>
    <Head :title="isEdit ? $t('admin.products.edit_product') : $t('admin.products.create_product')" />

    <AuthenticatedLayout>
        <template #header>{{ isEdit ? $t('admin.products.edit_product') : $t('admin.products.create_product') }}</template>

        <div class="mx-auto max-w-2xl overflow-hidden rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
            <form @submit.prevent="submit" class="space-y-6">
                <div>
                    <InputLabel :value="$t('admin.products.image')" />
                    <div class="mt-1 flex items-center gap-4">
                        <div class="h-20 w-20 shrink-0 overflow-hidden rounded-md bg-gray-100 dark:bg-gray-800">
                            <img v-if="imagePreview" :src="imagePreview" class="h-full w-full object-cover" />
                        </div>
                        <input type="file" accept="image/*" class="text-sm text-gray-600 dark:text-gray-400" @change="onImageChange" />
                    </div>
                    <InputError class="mt-2" :message="form.errors.image" />
                </div>

                <div v-if="isEdit">
                    <InputLabel :value="$t('admin.products.menus_in')" />
                    <div class="mt-1 flex flex-wrap gap-1.5">
                        <span
                            v-for="menuName in product.menus"
                            :key="menuName"
                            class="inline-flex rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300"
                        >{{ menuName }}</span>
                        <span v-if="product.menus.length === 0" class="text-sm text-gray-400">—</span>
                    </div>
                    <p class="mt-1 text-xs text-gray-500">{{ $t('admin.products.menus_hint') }}</p>
                </div>

                <div>
                    <InputLabel :value="$t('admin.categories.translations')" />

                    <div class="mt-2 flex gap-1 border-b border-gray-200 dark:border-gray-700">
                        <button
                            v-for="language in languages"
                            :key="language.code"
                            type="button"
                            class="border-b-2 px-3 py-2 text-sm font-medium"
                            :class="activeTab === language.code
                                ? 'border-transparent'
                                : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'"
                            :style="activeTab === language.code ? { borderBottomColor: 'var(--brand-primary)', color: 'var(--brand-primary)' } : {}"
                            @click="activeTab = language.code"
                        >
                            {{ language.native_name }}
                            <span
                                v-if="!form.translations[language.code]?.name"
                                class="ms-1 inline-block h-1.5 w-1.5 rounded-full bg-amber-500"
                                :title="$t('admin.products.missing_translations')"
                            ></span>
                        </button>
                    </div>

                    <div v-for="language in languages" :key="language.code" v-show="activeTab === language.code" class="mt-4 space-y-4">
                        <div>
                            <InputLabel :for="`name-${language.code}`" :value="`${$t('admin.categories.name_in')} ${language.native_name}`" />
                            <TextInput
                                :id="`name-${language.code}`"
                                v-model="form.translations[language.code].name"
                                :dir="language.direction"
                                class="mt-1 block w-full"
                                :autofocus="language.code === defaultLocaleCode"
                            />
                            <InputError class="mt-2" :message="form.errors[`translations.${language.code}.name`]" />
                        </div>
                        <div>
                            <InputLabel :for="`description-${language.code}`" :value="`${$t('admin.categories.description_in')} ${language.native_name}`" />
                            <textarea
                                :id="`description-${language.code}`"
                                v-model="form.translations[language.code].description"
                                :dir="language.direction"
                                rows="3"
                                class="focus-brand mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                            ></textarea>
                        </div>
                    </div>
                </div>

                <div>
                    <InputLabel for="slug" :value="$t('admin.products.slug')" />
                    <TextInput
                        id="slug"
                        v-model="form.slug"
                        class="mt-1 block w-full"
                        dir="ltr"
                        :placeholder="$t('admin.products.slug_auto')"
                        @input="onSlugInput"
                    />
                    <InputError class="mt-2" :message="form.errors.slug" />
                </div>

                <div>
                    <InputLabel for="price_cents" :value="$t('common.price')" />
                    <TextInput
                        id="price_cents"
                        v-model="priceInput"
                        type="number"
                        min="0"
                        step="0.01"
                        inputmode="decimal"
                        dir="ltr"
                        class="no-spinner mt-1 block w-full"
                        @blur="normalisePrice"
                    />
                    <InputError class="mt-2" :message="form.errors.price_cents" />
                </div>

                <div>
                    <InputLabel for="prep_time_minutes" :value="$t('admin.products.prep_time')" />
                    <TextInput
                        id="prep_time_minutes"
                        v-model.number="form.prep_time_minutes"
                        type="number"
                        min="0"
                        max="600"
                        class="mt-1 block w-32"
                        :placeholder="$t('admin.products.prep_time_placeholder')"
                    />
                    <p class="mt-1 text-xs text-gray-500">{{ $t('admin.products.prep_time_hint') }}</p>
                    <InputError class="mt-2" :message="form.errors.prep_time_minutes" />
                </div>

                <!-- Where this product appears on the menu. Indentation is
                     the nesting: a sub-category sits under its parent, at
                     any depth, so picking the right "Grill" is a matter of
                     looking rather than guessing. -->
                <div v-if="categories.length > 0">
                    <InputLabel :value="$t('admin.products.category')" />

                    <div class="mt-1.5 max-h-64 overflow-y-auto rounded-md border border-gray-200 p-1.5 dark:border-gray-700">
                        <label
                            v-for="category in categories"
                            :key="category.id"
                            class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-gray-50 dark:hover:bg-gray-800"
                            :style="{ paddingInlineStart: `${0.5 + category.depth * 1.25}rem` }"
                        >
                            <Checkbox
                                :checked="form.category_ids.includes(category.id)"
                                @update:checked="(checked) => toggleCategory(category.id, checked)"
                            />
                            <span :class="category.depth > 0 ? 'text-gray-600 dark:text-gray-400' : 'font-medium text-gray-900 dark:text-gray-100'">
                                <span v-if="category.depth > 0" class="text-gray-300">└</span>
                                {{ category.name }}
                            </span>
                        </label>
                    </div>

                    <p
                        v-if="removedCategories.length > 0"
                        class="mt-1.5 rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs text-amber-800 dark:border-amber-900/60 dark:bg-amber-900/20 dark:text-amber-300"
                    >
                        {{ $t('admin.products.category_removal_warning', { names: removedCategories.map((c) => c.name).join('، ') }) }}
                    </p>

                    <p class="mt-1 text-xs text-gray-500">{{ $t('admin.products.category_hint') }}</p>
                    <InputError class="mt-2" :message="form.errors.category_ids" />
                </div>
                <div>
                    <InputLabel for="station" :value="$t('admin.categories.station')" />
                    <select
                        id="station"
                        v-model="form.station"
                        class="focus-brand mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                    >
                        <option value="">{{ $t('admin.categories.no_station') }}</option>
                        <option v-for="option in printerOptions" :key="option.station" :value="option.station">
                            {{ option.name }}
                        </option>
                    </select>
                    <p class="mt-1 text-xs text-gray-500">{{ $t('admin.products.station_hint') }}</p>
                    <InputError class="mt-2" :message="form.errors.station" />
                </div>

                <div>
                    <InputLabel for="sort_order" :value="$t('admin.languages.sort_order')" />
                    <TextInput id="sort_order" v-model.number="form.sort_order" type="number" class="mt-1 block w-full" />
                </div>

                <label class="flex items-center">
                    <Checkbox v-model:checked="form.is_active" />
                    <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">{{ $t('common.active') }}</span>
                </label>

                <div>
                    <InputLabel :value="$t('admin.products.variations')" />
                    <p class="mt-1 text-xs text-gray-500">{{ $t('admin.products.variations_hint') }}</p>

                    <p v-if="modifierGroups.length === 0" class="mt-2 text-sm text-gray-400">
                        {{ $t('admin.products.variations_none') }}
                    </p>

                    <div v-else class="mt-3 space-y-3">
                        <div
                            v-for="group in modifierGroups"
                            :key="group.id"
                            class="overflow-hidden rounded-md border border-gray-200 dark:border-gray-700"
                        >
                            <label class="flex cursor-pointer items-center justify-between gap-3 bg-gray-50 px-3 py-2.5 dark:bg-gray-800/60">
                                <span class="flex items-center gap-2">
                                    <Checkbox :checked="form.modifier_group_ids.includes(group.id)" @update:checked="(checked) => {
                                        form.modifier_group_ids = checked
                                            ? [...form.modifier_group_ids, group.id]
                                            : form.modifier_group_ids.filter((id) => id !== group.id);
                                    }" />
                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ group.name }}</span>
                                </span>
                                <span class="text-xs text-gray-500">
                                    {{ group.is_required ? $t('admin.modifier_groups.required') : $t('admin.modifier_groups.optional') }}
                                    · {{ group.min_select }}–{{ group.max_select ?? '∞' }}
                                </span>
                            </label>

                            <div
                                v-if="form.modifier_group_ids.includes(group.id)"
                                class="divide-y divide-gray-100 dark:divide-gray-800"
                            >
                                <div
                                    v-for="modifier in group.modifiers"
                                    :key="modifier.id"
                                    class="flex flex-wrap items-center gap-3 px-3 py-2"
                                >
                                    <span
                                        class="min-w-32 flex-1 text-sm"
                                        :class="modifier.is_active ? 'text-gray-700 dark:text-gray-300' : 'text-gray-400 line-through'"
                                    >{{ modifier.name }}</span>

                                    <template v-if="modifier.is_active">
                                        <span
                                            class="w-16 shrink-0 text-end text-xs text-gray-400"
                                            :title="$t('admin.products.base_price')"
                                        >{{ (modifier.price_cents / 100).toFixed(2) }}</span>
                                        <TextInput
                                            :model-value="overrides[modifier.id].price_input"
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            inputmode="decimal"
                                            dir="ltr"
                                            class="no-spinner w-28 shrink-0 text-sm"
                                            :placeholder="$t('admin.products.custom_price_placeholder')"
                                            @update:model-value="(value) => setOverridePrice(modifier.id, value)"
                                            @blur="normaliseOverridePrice(modifier.id)"
                                        />
                                        <label class="flex shrink-0 items-center gap-1.5 text-xs text-gray-500">
                                            <Checkbox
                                                :checked="overrides[modifier.id].is_active !== false"
                                                @update:checked="(checked) => toggleOverrideAvailable(modifier.id, checked)"
                                            />
                                            {{ $t('admin.products.available_for_product') }}
                                        </label>
                                    </template>
                                    <span v-else class="text-xs text-amber-600">{{ $t('admin.products.unavailable_globally') }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <InputError class="mt-2" :message="form.errors.modifier_overrides" />
                </div>

                <div v-if="tags.length > 0">
                    <InputLabel :value="$t('nav.tags')" />
                    <div class="mt-2 flex flex-wrap gap-2">
                        <label
                            v-for="tag in tags"
                            :key="tag.id"
                            class="flex items-center gap-2 rounded-md border border-gray-200 px-3 py-1.5 text-sm dark:border-gray-700"
                        >
                            <Checkbox :checked="form.tag_ids.includes(tag.id)" @update:checked="(checked) => {
                                form.tag_ids = checked
                                    ? [...form.tag_ids, tag.id]
                                    : form.tag_ids.filter((id) => id !== tag.id);
                            }" />
                            <span>{{ tag.icon }} {{ tag.name }}</span>
                        </label>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <Link :href="route('admin.products.index')">
                        <SecondaryButton type="button">{{ $t('common.cancel') }}</SecondaryButton>
                    </Link>
                    <PrimaryButton :disabled="form.processing">{{ $t('common.save') }}</PrimaryButton>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
