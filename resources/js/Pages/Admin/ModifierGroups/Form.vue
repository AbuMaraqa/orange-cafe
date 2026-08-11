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
    group: {
        type: Object,
        default: null,
    },
});

const isEdit = computed(() => props.group !== null);

function emptyTranslations() {
    return Object.fromEntries(props.languages.map((l) => [l.code, { name: '' }]));
}

function newModifier() {
    return { id: null, price_cents: 0, is_active: true, translations: emptyTranslations() };
}

const form = useForm({
    is_required: props.group?.is_required ?? false,
    min_select: props.group?.min_select ?? 0,
    max_select: props.group?.max_select ?? null,
    is_active: props.group?.is_active ?? true,
    sort_order: props.group?.sort_order ?? 0,
    translations: props.group?.translations ?? emptyTranslations(),
    modifiers: props.group?.modifiers?.length ? props.group.modifiers : [newModifier()],
});

const activeTab = ref(props.languages.find((l) => l.is_default)?.code ?? props.languages[0]?.code);

function addModifier() {
    form.modifiers.push(newModifier());
}

function removeModifier(index) {
    form.modifiers.splice(index, 1);
}

function submit() {
    if (isEdit.value) {
        form.put(route('admin.modifier-groups.update', props.group.id));
    } else {
        form.post(route('admin.modifier-groups.store'));
    }
}
</script>

<template>
    <Head :title="isEdit ? $t('admin.modifier_groups.edit') : $t('admin.modifier_groups.create')" />

    <AuthenticatedLayout>
        <template #header>{{ isEdit ? $t('admin.modifier_groups.edit') : $t('admin.modifier_groups.create') }}</template>

        <div class="mx-auto max-w-2xl overflow-hidden rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
            <form @submit.prevent="submit" class="space-y-6">
                <div class="flex gap-1 border-b border-gray-200 dark:border-gray-700">
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
                    </button>
                </div>

                <div>
                    <InputLabel :value="`${$t('common.name')} (${languages.find((l) => l.code === activeTab)?.native_name})`" />
                    <TextInput
                        v-model="form.translations[activeTab].name"
                        :dir="languages.find((l) => l.code === activeTab)?.direction"
                        class="mt-1 block w-full"
                        :placeholder="$t('admin.modifier_groups.name_example')"
                    />
                    <InputError class="mt-2" :message="form.errors[`translations.${activeTab}.name`]" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <InputLabel for="min_select" :value="$t('admin.modifier_groups.min_select')" />
                        <TextInput id="min_select" v-model.number="form.min_select" type="number" min="0" class="mt-1 block w-full" />
                        <InputError class="mt-2" :message="form.errors.min_select" />
                    </div>
                    <div>
                        <InputLabel for="max_select" :value="$t('admin.modifier_groups.max_select')" />
                        <TextInput id="max_select" v-model.number="form.max_select" type="number" min="1" class="mt-1 block w-full" :placeholder="$t('admin.modifier_groups.unlimited')" />
                        <InputError class="mt-2" :message="form.errors.max_select" />
                    </div>
                </div>

                <div class="flex gap-6">
                    <label class="flex items-center">
                        <Checkbox v-model:checked="form.is_required" />
                        <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">{{ $t('admin.modifier_groups.required') }}</span>
                    </label>
                    <label class="flex items-center">
                        <Checkbox v-model:checked="form.is_active" />
                        <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">{{ $t('common.active') }}</span>
                    </label>
                </div>

                <div>
                    <div class="flex items-center justify-between">
                        <InputLabel :value="$t('admin.modifier_groups.options')" />
                        <button type="button" class="text-sm text-gray-600 underline hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100" @click="addModifier">
                            + {{ $t('admin.modifier_groups.add_option') }}
                        </button>
                    </div>

                    <div class="mt-3 space-y-3">
                        <div
                            v-for="(modifier, index) in form.modifiers"
                            :key="index"
                            class="flex items-start gap-3 rounded-md border border-gray-200 p-3 dark:border-gray-700"
                        >
                            <div class="flex-1">
                                <TextInput
                                    v-model="modifier.translations[activeTab].name"
                                    :dir="languages.find((l) => l.code === activeTab)?.direction"
                                    class="block w-full"
                                    :placeholder="$t('common.name')"
                                />
                                <InputError class="mt-1" :message="form.errors[`modifiers.${index}.translations.${activeTab}.name`]" />
                            </div>
                            <div class="w-28">
                                <TextInput v-model.number="modifier.price_cents" type="number" class="block w-full" :placeholder="$t('common.price')" />
                            </div>
                            <label class="flex items-center pt-2">
                                <Checkbox v-model:checked="modifier.is_active" />
                            </label>
                            <button
                                type="button"
                                class="pt-2 text-red-500 hover:text-red-700"
                                :disabled="form.modifiers.length === 1"
                                @click="removeModifier(index)"
                            >
                                ×
                            </button>
                        </div>
                    </div>
                    <InputError class="mt-2" :message="form.errors.modifiers" />
                </div>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <Link :href="route('admin.modifier-groups.index')">
                        <SecondaryButton type="button">{{ $t('common.cancel') }}</SecondaryButton>
                    </Link>
                    <PrimaryButton :disabled="form.processing">{{ $t('common.save') }}</PrimaryButton>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
