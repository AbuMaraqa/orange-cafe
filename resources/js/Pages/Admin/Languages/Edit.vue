<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    language: {
        type: Object,
        required: true,
    },
});

const form = useForm({
    name: props.language.name,
    native_name: props.language.native_name,
    direction: props.language.direction,
    is_default: props.language.is_default,
    is_active: props.language.is_active,
    sort_order: props.language.sort_order,
});

function submit() {
    form.put(route('admin.languages.update', props.language.id));
}
</script>

<template>
    <Head :title="$t('admin.languages.edit_language')" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                {{ $t('admin.languages.edit_language') }} — {{ language.native_name }}
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg dark:bg-gray-800">
                    <form @submit.prevent="submit" class="space-y-4">
                        <div>
                            <InputLabel for="code" :value="$t('admin.languages.code')" />
                            <TextInput id="code" :model-value="language.code" class="mt-1 block w-full" disabled />
                            <p class="mt-1 text-xs text-gray-500">{{ $t('admin.languages.code_locked_hint') }}</p>
                        </div>

                        <div>
                            <InputLabel for="native_name" :value="$t('admin.languages.native_name')" />
                            <TextInput id="native_name" v-model="form.native_name" class="mt-1 block w-full" required autofocus />
                            <InputError class="mt-2" :message="form.errors.native_name" />
                        </div>

                        <div>
                            <InputLabel for="name" :value="$t('admin.languages.name')" />
                            <TextInput id="name" v-model="form.name" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.name" />
                        </div>

                        <div>
                            <InputLabel for="direction" :value="$t('admin.languages.direction')" />
                            <select
                                id="direction"
                                v-model="form.direction"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                            >
                                <option value="ltr">{{ $t('admin.languages.ltr') }}</option>
                                <option value="rtl">{{ $t('admin.languages.rtl') }}</option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.direction" />
                        </div>

                        <div>
                            <InputLabel for="sort_order" :value="$t('admin.languages.sort_order')" />
                            <TextInput id="sort_order" v-model.number="form.sort_order" type="number" class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="form.errors.sort_order" />
                        </div>

                        <label class="flex items-center" :class="{ 'opacity-50': language.is_default }">
                            <Checkbox v-model:checked="form.is_active" :disabled="language.is_default" />
                            <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">{{ $t('admin.languages.is_active') }}</span>
                        </label>

                        <label class="flex items-center">
                            <Checkbox v-model:checked="form.is_default" />
                            <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">{{ $t('admin.languages.is_default') }}</span>
                        </label>

                        <div class="flex items-center justify-end gap-3 pt-2">
                            <Link :href="route('admin.languages.index')">
                                <SecondaryButton type="button">{{ $t('common.cancel') }}</SecondaryButton>
                            </Link>
                            <PrimaryButton :disabled="form.processing">{{ $t('common.save') }}</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
