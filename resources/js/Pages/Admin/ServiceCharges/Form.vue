<script setup>
import { computed, ref } from 'vue';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import MoneyInput from '@/Components/Accounting/MoneyInput.vue';
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
    charge: {
        type: Object,
        default: null,
    },
});

const isEdit = computed(() => props.charge !== null);

const emptyTranslations = Object.fromEntries(props.languages.map((l) => [l.code, { name: '' }]));

// The amount travels as typed and the server turns it into cents — see
// MoneyInput and NormalisesMoney. Editing starts from the stored figure
// written back out in currency units.
const form = useForm({
    amount: props.charge ? (props.charge.amount_cents / 100).toFixed(2) : '',
    is_active: props.charge?.is_active ?? true,
    sort_order: props.charge?.sort_order ?? 0,
    translations: props.charge?.translations ?? emptyTranslations,
});

const defaultLocaleCode = props.languages.find((l) => l.is_default)?.code ?? props.languages[0]?.code;
const activeTab = ref(defaultLocaleCode);

function submit() {
    if (isEdit.value) {
        form.put(route('admin.service-charges.update', props.charge.id));
    } else {
        form.post(route('admin.service-charges.store'));
    }
}
</script>

<template>
    <Head :title="isEdit ? $t('admin.service_charges.edit') : $t('admin.service_charges.create')" />

    <AuthenticatedLayout>
        <template #header>{{ isEdit ? $t('admin.service_charges.edit') : $t('admin.service_charges.create') }}</template>

        <div class="mx-auto max-w-lg overflow-hidden rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
            <form @submit.prevent="submit" class="space-y-6">
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
                    <p class="mt-2 text-xs text-gray-500">{{ $t('admin.service_charges.name_hint') }}</p>
                </div>

                <div>
                    <MoneyInput id="amount" v-model="form.amount" :label="$t('common.price')" required />
                    <p class="mt-1 text-xs text-gray-500">{{ $t('admin.service_charges.amount_hint') }}</p>
                    <InputError class="mt-2" :message="form.errors.amount_cents" />
                </div>

                <div>
                    <InputLabel for="sort_order" :value="$t('common.sort_order')" />
                    <TextInput id="sort_order" v-model="form.sort_order" type="number" min="0" dir="ltr" class="mt-1 block w-28" />
                    <InputError class="mt-2" :message="form.errors.sort_order" />
                </div>

                <label class="flex items-center">
                    <Checkbox v-model:checked="form.is_active" />
                    <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">{{ $t('common.active') }}</span>
                </label>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <Link :href="route('admin.service-charges.index')">
                        <SecondaryButton type="button">{{ $t('common.cancel') }}</SecondaryButton>
                    </Link>
                    <PrimaryButton :disabled="form.processing">{{ $t('common.save') }}</PrimaryButton>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
