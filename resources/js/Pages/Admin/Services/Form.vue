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
    service: {
        type: Object,
        default: null,
    },
});

const isEdit = computed(() => props.service !== null);

const emptyTranslations = Object.fromEntries(props.languages.map((l) => [l.code, { name: '' }]));

const form = useForm({
    slug: props.service?.slug ?? '',
    icon: props.service?.icon ?? '',
    image: null,
    color: props.service?.color ?? '',
    is_active: props.service?.is_active ?? true,
    sort_order: props.service?.sort_order ?? 0,
    translations: props.service?.translations ?? emptyTranslations,
});

const imagePreview = ref(props.service?.image_url ?? null);

function onImageChange(event) {
    const file = event.target.files[0];
    form.image = file ?? null;
    imagePreview.value = file ? URL.createObjectURL(file) : (props.service?.image_url ?? null);
}

const defaultLocaleCode = props.languages.find((l) => l.is_default)?.code ?? props.languages[0]?.code;
const activeTab = ref(defaultLocaleCode);

// Typing the name auto-fills the slug, so there's no separate field to
// think about up front — stops the moment the admin edits the slug
// themselves (an existing service's slug is also left alone, since it may
// already be linked to past waiter-call history).
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
        form.put(route('admin.services.update', props.service.id));
    } else {
        form.post(route('admin.services.store'));
    }
}
</script>

<template>
    <Head :title="isEdit ? $t('admin.services.edit') : $t('admin.services.create')" />

    <AuthenticatedLayout>
        <template #header>{{ isEdit ? $t('admin.services.edit') : $t('admin.services.create') }}</template>

        <div class="mx-auto max-w-lg overflow-hidden rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
            <form @submit.prevent="submit" class="space-y-6">
                <div>
                    <InputLabel for="icon" :value="$t('admin.services.icon')" />
                    <TextInput id="icon" v-model="form.icon" class="mt-1 block w-24 text-center text-lg" placeholder="🔔" />
                    <p class="mt-1 text-xs text-gray-500">{{ $t('admin.services.icon_hint') }}</p>
                    <InputError class="mt-2" :message="form.errors.icon" />
                </div>

                <div>
                    <InputLabel :value="$t('admin.services.image')" />
                    <div class="mt-1 flex items-center gap-4">
                        <div class="h-16 w-16 shrink-0 overflow-hidden rounded-lg bg-gray-100 dark:bg-gray-800">
                            <img v-if="imagePreview" :src="imagePreview" class="h-full w-full object-cover" />
                        </div>
                        <input type="file" accept="image/*" class="text-sm text-gray-600 dark:text-gray-400" @change="onImageChange" />
                    </div>
                    <p class="mt-1 text-xs text-gray-500">{{ $t('admin.services.image_hint') }}</p>
                    <InputError class="mt-2" :message="form.errors.image" />
                </div>

                <div>
                    <InputLabel for="color" :value="$t('admin.services.color')" />
                    <div class="mt-1 flex items-center gap-2">
                        <input id="color" v-model="form.color" type="color" class="h-9 w-12 rounded border border-gray-300 dark:border-gray-700" />
                        <TextInput v-model="form.color" class="block w-32" dir="ltr" placeholder="#f59e0b" />
                    </div>
                    <p class="mt-1 text-xs text-gray-500">{{ $t('admin.services.color_hint') }}</p>
                    <InputError class="mt-2" :message="form.errors.color" />
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
                    <Link :href="route('admin.services.index')">
                        <SecondaryButton type="button">{{ $t('common.cancel') }}</SecondaryButton>
                    </Link>
                    <PrimaryButton :disabled="form.processing">{{ $t('common.save') }}</PrimaryButton>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
