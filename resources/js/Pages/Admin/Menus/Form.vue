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
import { useI18n } from 'vue-i18n';

const props = defineProps({
    languages: {
        type: Array,
        required: true,
    },
    publishStatuses: {
        type: Array,
        required: true,
    },
    menu: {
        type: Object,
        default: null,
    },
});

const { t } = useI18n();

const isEdit = computed(() => props.menu !== null);

const emptyTranslations = Object.fromEntries(
    props.languages.map((l) => [l.code, { name: '', description: '' }]),
);

const form = useForm({
    slug: props.menu?.slug ?? '',
    publish_status: props.menu?.publish_status ?? 'draft',
    is_active: props.menu?.is_active ?? true,
    starts_at: props.menu?.starts_at ?? '',
    ends_at: props.menu?.ends_at ?? '',
    start_time: props.menu?.start_time ?? '',
    end_time: props.menu?.end_time ?? '',
    days_of_week: props.menu?.days_of_week ?? [],
    sort_order: props.menu?.sort_order ?? 0,
    translations: props.menu?.translations ?? emptyTranslations,
});

const activeTab = ref(props.languages.find((l) => l.is_default)?.code ?? props.languages[0]?.code);

const weekdays = computed(() => [
    { value: 0, label: t('admin.menus.days.sun') },
    { value: 1, label: t('admin.menus.days.mon') },
    { value: 2, label: t('admin.menus.days.tue') },
    { value: 3, label: t('admin.menus.days.wed') },
    { value: 4, label: t('admin.menus.days.thu') },
    { value: 5, label: t('admin.menus.days.fri') },
    { value: 6, label: t('admin.menus.days.sat') },
]);

function toggleDay(day) {
    form.days_of_week = form.days_of_week.includes(day)
        ? form.days_of_week.filter((d) => d !== day)
        : [...form.days_of_week, day];
}

function submit() {
    if (isEdit.value) {
        form.put(route('admin.menus.update', props.menu.id));
    } else {
        form.post(route('admin.menus.store'));
    }
}
</script>

<template>
    <Head :title="isEdit ? $t('admin.menus.edit_menu') : $t('admin.menus.create_menu')" />

    <AuthenticatedLayout>
        <template #header>{{ isEdit ? $t('admin.menus.edit_menu') : $t('admin.menus.create_menu') }}</template>

        <div class="mx-auto max-w-2xl overflow-hidden rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
            <form @submit.prevent="submit" class="space-y-6">
                <div>
                    <InputLabel for="slug" :value="$t('admin.categories.slug')" />
                    <TextInput id="slug" v-model="form.slug" class="mt-1 block w-full" required autofocus dir="ltr" />
                    <InputError class="mt-2" :message="form.errors.slug" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <InputLabel for="publish_status" :value="$t('admin.menus.publish_status')" />
                        <select
                            id="publish_status"
                            v-model="form.publish_status"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                        >
                            <option v-for="status in publishStatuses" :key="status.value" :value="status.value">
                                {{ status.label }}
                            </option>
                        </select>
                        <InputError class="mt-2" :message="form.errors.publish_status" />
                    </div>
                    <div>
                        <InputLabel for="sort_order" :value="$t('admin.languages.sort_order')" />
                        <TextInput id="sort_order" v-model.number="form.sort_order" type="number" class="mt-1 block w-full" />
                    </div>
                </div>

                <label class="flex items-center">
                    <Checkbox v-model:checked="form.is_active" />
                    <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">{{ $t('common.active') }}</span>
                </label>

                <div class="rounded-md border border-gray-200 p-4 dark:border-gray-800">
                    <p class="mb-3 text-sm font-medium text-gray-700 dark:text-gray-300">{{ $t('admin.menus.schedule') }}</p>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <InputLabel for="starts_at" :value="$t('admin.menus.starts_at')" />
                            <TextInput id="starts_at" v-model="form.starts_at" type="date" class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="form.errors.starts_at" />
                        </div>
                        <div>
                            <InputLabel for="ends_at" :value="$t('admin.menus.ends_at')" />
                            <TextInput id="ends_at" v-model="form.ends_at" type="date" class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="form.errors.ends_at" />
                        </div>
                        <div>
                            <InputLabel for="start_time" :value="$t('admin.menus.start_time')" />
                            <TextInput id="start_time" v-model="form.start_time" type="time" class="mt-1 block w-full" />
                        </div>
                        <div>
                            <InputLabel for="end_time" :value="$t('admin.menus.end_time')" />
                            <TextInput id="end_time" v-model="form.end_time" type="time" class="mt-1 block w-full" />
                        </div>
                    </div>

                    <div class="mt-4">
                        <InputLabel :value="$t('admin.menus.days_of_week')" />
                        <div class="mt-2 flex flex-wrap gap-2">
                            <button
                                v-for="day in weekdays"
                                :key="day.value"
                                type="button"
                                class="rounded-md border px-3 py-1.5 text-sm"
                                :class="form.days_of_week.includes(day.value)
                                    ? 'border-gray-900 bg-gray-900 text-white dark:border-gray-100 dark:bg-gray-100 dark:text-gray-900'
                                    : 'border-gray-200 text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800'"
                                @click="toggleDay(day.value)"
                            >
                                {{ day.label }}
                            </button>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">{{ $t('admin.menus.days_of_week_hint') }}</p>
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
                    <Link :href="route('admin.menus.index')">
                        <SecondaryButton type="button">{{ $t('common.cancel') }}</SecondaryButton>
                    </Link>
                    <PrimaryButton :disabled="form.processing">{{ $t('common.save') }}</PrimaryButton>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
