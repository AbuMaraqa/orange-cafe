<script setup>
import { computed, ref, watch } from 'vue';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

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
    category_ids: [],
    is_active: props.product?.is_active ?? true,
    sort_order: props.product?.sort_order ?? 0,
    modifier_group_ids: props.product?.modifier_group_ids ?? [],
    tag_ids: props.product?.tag_ids ?? [],
    translations: props.product?.translations ?? emptyTranslations,
});

// The form field itself stays in cents (what the backend expects) — this
// just converts what the admin types/sees to and from whole currency
// units, the same way Menu Builder's price override field does.
const priceDisplay = computed({
    get: () => (form.price_cents / 100).toFixed(2),
    set: (value) => {
        const cents = Math.round(Number(value) * 100);
        form.price_cents = Number.isFinite(cents) ? cents : 0;
    },
});

const imagePreview = ref(props.product?.image_url ?? null);

// Categories the product is already placed in — shown read-only here since
// removing a placement can drop per-category customization (price
// override, sort order, featured flag) that only Menu Builder shows.
// Checking a box below only ever adds a new placement, so the checklist
// itself excludes categories the product is already in.
const assignedCategories = computed(() => {
    const ids = new Set(props.product?.category_ids ?? []);
    return props.categories.filter((category) => ids.has(category.id));
});
const availableCategories = computed(() => {
    const ids = new Set(props.product?.category_ids ?? []);
    return props.categories.filter((category) => !ids.has(category.id));
});
const defaultLocaleCode = props.languages.find((l) => l.is_default)?.code ?? props.languages[0]?.code;
const activeTab = ref(defaultLocaleCode);

// Typing the name auto-fills the slug, so there's no separate field to
// think about up front — stops the moment the admin edits the slug
// themselves (an existing product's slug is also left alone, since it
// may already be linked elsewhere).
const slugEditedManually = ref(isEdit.value);

watch(() => form.translations[defaultLocaleCode]?.name, (name) => {
    if (!slugEditedManually.value && name) {
        form.slug = name.trim().replace(/\s+/g, '-');
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
                    <TextInput id="slug" v-model="form.slug" class="mt-1 block w-full" dir="ltr" required @input="onSlugInput" />
                    <InputError class="mt-2" :message="form.errors.slug" />
                </div>

                <div>
                    <InputLabel for="price_cents" :value="$t('common.price')" />
                    <TextInput id="price_cents" v-model="priceDisplay" type="number" min="0" step="0.01" class="mt-1 block w-full" />
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

                <div v-if="assignedCategories.length > 0 || availableCategories.length > 0">
                    <InputLabel :value="$t('admin.products.category')" />

                    <div v-if="assignedCategories.length > 0" class="mt-1 flex flex-wrap gap-1.5">
                        <span
                            v-for="category in assignedCategories"
                            :key="category.id"
                            class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-1 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300"
                        >
                            {{ category.name }}
                        </span>
                    </div>

                    <div v-if="availableCategories.length > 0" class="mt-2 flex flex-wrap gap-2">
                        <label
                            v-for="category in availableCategories"
                            :key="category.id"
                            class="flex items-center gap-2 rounded-md border border-gray-200 px-3 py-1.5 text-sm dark:border-gray-700"
                        >
                            <Checkbox :checked="form.category_ids.includes(category.id)" @update:checked="(checked) => {
                                form.category_ids = checked
                                    ? [...form.category_ids, category.id]
                                    : form.category_ids.filter((id) => id !== category.id);
                            }" />
                            {{ category.name }}
                        </label>
                    </div>

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

                <div v-if="modifierGroups.length > 0">
                    <InputLabel :value="$t('nav.modifier_groups')" />
                    <div class="mt-2 flex flex-wrap gap-2">
                        <label
                            v-for="group in modifierGroups"
                            :key="group.id"
                            class="flex items-center gap-2 rounded-md border border-gray-200 px-3 py-1.5 text-sm dark:border-gray-700"
                        >
                            <Checkbox :checked="form.modifier_group_ids.includes(group.id)" @update:checked="(checked) => {
                                form.modifier_group_ids = checked
                                    ? [...form.modifier_group_ids, group.id]
                                    : form.modifier_group_ids.filter((id) => id !== group.id);
                            }" />
                            {{ group.name }}
                        </label>
                    </div>
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
