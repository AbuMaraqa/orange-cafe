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
    tag: {
        type: Object,
        default: null,
    },
});

const isEdit = computed(() => props.tag !== null);

const emptyTranslations = Object.fromEntries(props.languages.map((l) => [l.code, { name: '' }]));

const form = useForm({
    slug: props.tag?.slug ?? '',
    icon: props.tag?.icon ?? '',
    is_active: props.tag?.is_active ?? true,
    sort_order: props.tag?.sort_order ?? 0,
    translations: props.tag?.translations ?? emptyTranslations,
});

const defaultLocaleCode = props.languages.find((l) => l.is_default)?.code ?? props.languages[0]?.code;
const activeTab = ref(defaultLocaleCode);

// Typing the name auto-fills the slug, so there's no separate field to
// think about up front — stops the moment the admin edits the slug
// themselves (an existing tag's slug is also left alone, since it may
// already be linked elsewhere).
const slugEditedManually = ref(isEdit.value);

watch(() => form.translations[defaultLocaleCode]?.name, (name) => {
    if (!slugEditedManually.value && name) {
        form.slug = name.trim().replace(/\s+/g, '-');
    }
});

function onSlugInput() {
    slugEditedManually.value = true;
}

function submit() {
    if (isEdit.value) {
        form.put(route('admin.tags.update', props.tag.id));
    } else {
        form.post(route('admin.tags.store'));
    }
}
</script>

<template>
    <Head :title="isEdit ? $t('admin.tags.edit') : $t('admin.tags.create')" />

    <AuthenticatedLayout>
        <template #header>{{ isEdit ? $t('admin.tags.edit') : $t('admin.tags.create') }}</template>

        <div class="mx-auto max-w-lg overflow-hidden rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
            <form @submit.prevent="submit" class="space-y-6">
                <div>
                    <InputLabel for="icon" :value="$t('admin.tags.icon')" />
                    <TextInput id="icon" v-model="form.icon" class="mt-1 block w-24 text-center text-lg" placeholder="🌶️" />
                    <p class="mt-1 text-xs text-gray-500">{{ $t('admin.tags.icon_hint') }}</p>
                    <InputError class="mt-2" :message="form.errors.icon" />
                </div>

                <div>
                    <div class="flex gap-1 border-b border-gray-200 dark:border-gray-700">
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
                        </button>
                    </div>
                    <div v-for="language in languages" :key="language.code" v-show="activeTab === language.code" class="mt-4">
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
                </div>

                <div>
                    <InputLabel for="slug" :value="$t('admin.categories.slug')" />
                    <TextInput id="slug" v-model="form.slug" class="mt-1 block w-full" required dir="ltr" @input="onSlugInput" />
                    <InputError class="mt-2" :message="form.errors.slug" />
                </div>

                <label class="flex items-center">
                    <Checkbox v-model:checked="form.is_active" />
                    <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">{{ $t('common.active') }}</span>
                </label>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <Link :href="route('admin.tags.index')">
                        <SecondaryButton type="button">{{ $t('common.cancel') }}</SecondaryButton>
                    </Link>
                    <PrimaryButton :disabled="form.processing">{{ $t('common.save') }}</PrimaryButton>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
