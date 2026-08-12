<script setup>
import { computed, ref } from 'vue';
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
    is_active: props.product?.is_active ?? true,
    sort_order: props.product?.sort_order ?? 0,
    modifier_group_ids: props.product?.modifier_group_ids ?? [],
    tag_ids: props.product?.tag_ids ?? [],
    translations: props.product?.translations ?? emptyTranslations,
});

const imagePreview = ref(props.product?.image_url ?? null);
const activeTab = ref(props.languages.find((l) => l.is_default)?.code ?? props.languages[0]?.code);

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
                    <InputLabel for="slug" :value="$t('admin.products.slug')" />
                    <TextInput id="slug" v-model="form.slug" class="mt-1 block w-full" required />
                    <InputError class="mt-2" :message="form.errors.slug" />
                </div>

                <div>
                    <InputLabel for="price_cents" :value="$t('common.price')" />
                    <TextInput id="price_cents" v-model.number="form.price_cents" type="number" min="0" class="mt-1 block w-full" />
                    <p class="mt-1 text-xs text-gray-500">{{ $t('admin.products.price_placeholder') }}</p>
                    <InputError class="mt-2" :message="form.errors.price_cents" />
                </div>

                <div>
                    <InputLabel for="station" :value="$t('admin.categories.station')" />
                    <TextInput id="station" v-model="form.station" class="mt-1 block w-full" :placeholder="$t('admin.products.station_placeholder')" />
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

                <div>
                    <InputLabel :value="$t('admin.categories.translations')" />

                    <div class="mt-2 flex gap-1 border-b border-gray-200 dark:border-gray-700">
                        <button
                            v-for="language in languages"
                            :key="language.code"
                            type="button"
                            class="border-b-2 px-3 py-2 text-sm font-medium"
                            :class="activeTab === language.code
                                ? 'border-gray-900 text-gray-900 dark:border-gray-100 dark:text-gray-100'
                                : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'"
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
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                            ></textarea>
                        </div>
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
