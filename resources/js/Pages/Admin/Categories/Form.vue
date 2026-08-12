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
    parentOptions: {
        type: Array,
        default: () => [],
    },
    menuOptions: {
        type: Array,
        default: () => [],
    },
    category: {
        type: Object,
        default: null,
    },
});

const isEdit = computed(() => props.category !== null);

const emptyTranslations = Object.fromEntries(
    props.languages.map((l) => [l.code, { name: '', description: '' }]),
);

const form = useForm({
    menu_id: props.category?.menu_id ?? props.menuOptions[0]?.id ?? null,
    parent_id: props.category?.parent_id ?? null,
    slug: props.category?.slug ?? '',
    station: props.category?.station ?? '',
    is_active: props.category?.is_active ?? true,
    sort_order: props.category?.sort_order ?? 0,
    translations: props.category?.translations ?? emptyTranslations,
});

const activeTab = ref(props.languages.find((l) => l.is_default)?.code ?? props.languages[0]?.code);

function submit() {
    if (isEdit.value) {
        form.put(route('admin.categories.update', props.category.id));
    } else {
        form.post(route('admin.categories.store'));
    }
}
</script>

<template>
    <Head :title="isEdit ? $t('admin.categories.edit_category') : $t('admin.categories.create_category')" />

    <AuthenticatedLayout>
        <template #header>{{ isEdit ? $t('admin.categories.edit_category') : $t('admin.categories.create_category') }}</template>

        <div class="mx-auto max-w-2xl overflow-hidden rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
            <form @submit.prevent="submit" class="space-y-6">
                <div v-if="!form.parent_id">
                    <InputLabel for="menu_id" :value="$t('admin.categories.menu')" />
                    <select
                        v-if="!isEdit"
                        id="menu_id"
                        v-model.number="form.menu_id"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                    >
                        <option v-for="option in menuOptions" :key="option.id" :value="option.id">
                            {{ option.name }}
                        </option>
                    </select>
                    <div v-else class="mt-1 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">
                        {{ menuOptions.find((m) => m.id === form.menu_id)?.name }}
                        <p class="mt-0.5 text-xs text-gray-400">{{ $t('admin.categories.menu_locked_hint') }}</p>
                    </div>
                    <InputError class="mt-2" :message="form.errors.menu_id" />
                </div>

                <div v-if="parentOptions.length > 0">
                    <InputLabel for="parent_id" :value="$t('admin.categories.parent')" />
                    <select
                        id="parent_id"
                        v-model="form.parent_id"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                    >
                        <option :value="null">{{ $t('admin.categories.no_parent') }}</option>
                        <option v-for="option in parentOptions" :key="option.id" :value="option.id">
                            {{ option.name }}
                        </option>
                    </select>
                    <InputError class="mt-2" :message="form.errors.parent_id" />
                </div>

                <div>
                    <InputLabel for="slug" :value="$t('admin.categories.slug')" />
                    <TextInput id="slug" v-model="form.slug" class="mt-1 block w-full" required autofocus />
                    <InputError class="mt-2" :message="form.errors.slug" />
                </div>

                <div>
                    <InputLabel for="station" :value="$t('admin.categories.station')" />
                    <TextInput id="station" v-model="form.station" class="mt-1 block w-full" :placeholder="$t('admin.categories.station_placeholder')" />
                    <p class="mt-1 text-xs text-gray-500">{{ $t('admin.categories.station_hint') }}</p>
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
                                :title="$t('admin.categories.missing_translations')"
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
                    <Link :href="route('admin.categories.index')">
                        <SecondaryButton type="button">{{ $t('common.cancel') }}</SecondaryButton>
                    </Link>
                    <PrimaryButton :disabled="form.processing">{{ $t('common.save') }}</PrimaryButton>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
