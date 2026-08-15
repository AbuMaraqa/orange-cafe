<script setup>
import { ref } from 'vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    setting: {
        type: Object,
        required: true,
    },
});

const form = useForm({
    name: props.setting.name,
    tagline: props.setting.tagline ?? '',
    address: props.setting.address ?? '',
    logo: null,
    cover_image: null,
    primary_color: props.setting.primary_color,
    secondary_color: props.setting.secondary_color,
    font_family: props.setting.font_family,
    currency_code: props.setting.currency_code,
    currency_symbol: props.setting.currency_symbol,
    phone: props.setting.phone ?? '',
    whatsapp: props.setting.whatsapp ?? '',
    instagram: props.setting.instagram ?? '',
    facebook: props.setting.facebook ?? '',
    wifi_ssid: props.setting.wifi_ssid ?? '',
    wifi_password: props.setting.wifi_password ?? '',
    receipt_footer_text: props.setting.receipt_footer_text ?? '',
});

const logoPreview = ref(props.setting.logo_url);
const coverPreview = ref(props.setting.cover_image_url);

function onLogoChange(event) {
    const file = event.target.files[0];
    form.logo = file ?? null;
    logoPreview.value = file ? URL.createObjectURL(file) : props.setting.logo_url;
}

function onCoverChange(event) {
    const file = event.target.files[0];
    form.cover_image = file ?? null;
    coverPreview.value = file ? URL.createObjectURL(file) : props.setting.cover_image_url;
}

function submit() {
    form.post(route('admin.settings.update'));
}
</script>

<template>
    <Head :title="$t('nav.settings')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('nav.settings') }}</template>

        <form @submit.prevent="submit" class="mx-auto max-w-2xl space-y-6">
            <section class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
                <h3 class="mb-4 font-medium text-gray-900 dark:text-gray-100">{{ $t('settings.branding') }}</h3>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <InputLabel :value="$t('admin.languages.name')" />
                        <div class="mt-1 h-16 w-16 overflow-hidden rounded-md bg-gray-100 dark:bg-gray-800">
                            <img v-if="logoPreview" :src="logoPreview" class="h-full w-full object-cover" />
                        </div>
                        <input type="file" accept="image/*" class="mt-2 text-xs text-gray-600 dark:text-gray-400" @change="onLogoChange" />
                        <InputError class="mt-1" :message="form.errors.logo" />
                    </div>
                    <div>
                        <InputLabel :value="$t('settings.cover_image')" />
                        <div class="mt-1 h-16 w-28 overflow-hidden rounded-md bg-gray-100 dark:bg-gray-800">
                            <img v-if="coverPreview" :src="coverPreview" class="h-full w-full object-cover" />
                        </div>
                        <input type="file" accept="image/*" class="mt-2 text-xs text-gray-600 dark:text-gray-400" @change="onCoverChange" />
                        <InputError class="mt-1" :message="form.errors.cover_image" />
                    </div>
                </div>

                <div class="mt-4">
                    <InputLabel for="name" :value="$t('settings.restaurant_name')" />
                    <TextInput id="name" v-model="form.name" class="mt-1 block w-full" required />
                    <InputError class="mt-2" :message="form.errors.name" />
                </div>

                <div class="mt-4">
                    <InputLabel for="tagline" :value="$t('settings.tagline')" />
                    <TextInput id="tagline" v-model="form.tagline" class="mt-1 block w-full" />
                </div>

                <div class="mt-4">
                    <InputLabel for="address" :value="$t('admin.languages.address')" />
                    <TextInput id="address" v-model="form.address" class="mt-1 block w-full" />
                </div>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
                <h3 class="mb-4 font-medium text-gray-900 dark:text-gray-100">{{ $t('settings.appearance') }}</h3>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <InputLabel for="primary_color" :value="$t('admin.languages.primary_color')" />
                        <div class="mt-1 flex items-center gap-2">
                            <input id="primary_color" v-model="form.primary_color" type="color" class="h-9 w-12 rounded border border-gray-300 dark:border-gray-700" />
                            <TextInput v-model="form.primary_color" class="block w-full" />
                        </div>
                        <InputError class="mt-2" :message="form.errors.primary_color" />
                    </div>
                    <div>
                        <InputLabel for="secondary_color" :value="$t('admin.languages.secondary_color')" />
                        <div class="mt-1 flex items-center gap-2">
                            <input id="secondary_color" v-model="form.secondary_color" type="color" class="h-9 w-12 rounded border border-gray-300 dark:border-gray-700" />
                            <TextInput v-model="form.secondary_color" class="block w-full" />
                        </div>
                        <InputError class="mt-2" :message="form.errors.secondary_color" />
                    </div>
                </div>

                <div class="mt-4">
                    <InputLabel for="font_family" :value="$t('settings.font')" />
                    <select
                        id="font_family"
                        v-model="form.font_family"
                        class="focus-brand mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                    >
                        <option value="tajawal">Tajawal</option>
                        <option value="almarai">Almarai</option>
                        <option value="system">{{ $t('settings.font_system') }}</option>
                    </select>
                    <InputError class="mt-2" :message="form.errors.font_family" />
                </div>

                <div class="mt-4 grid grid-cols-2 gap-4">
                    <div>
                        <InputLabel for="currency_code" :value="$t('settings.currency_code')" />
                        <TextInput id="currency_code" v-model="form.currency_code" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <InputLabel for="currency_symbol" :value="$t('settings.currency_symbol')" />
                        <TextInput id="currency_symbol" v-model="form.currency_symbol" class="mt-1 block w-full" />
                    </div>
                </div>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
                <h3 class="mb-4 font-medium text-gray-900 dark:text-gray-100">{{ $t('settings.contact') }}</h3>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <InputLabel for="phone" :value="$t('admin.languages.phone')" />
                        <TextInput id="phone" v-model="form.phone" class="mt-1 block w-full" dir="ltr" placeholder="+970 59 000 0000" />
                    </div>
                    <div>
                        <InputLabel for="whatsapp" value="WhatsApp" />
                        <TextInput id="whatsapp" v-model="form.whatsapp" class="mt-1 block w-full" dir="ltr" placeholder="+970590000000" />
                    </div>
                    <div>
                        <InputLabel for="instagram" value="Instagram" />
                        <TextInput id="instagram" v-model="form.instagram" class="mt-1 block w-full" dir="ltr" placeholder="username" />
                    </div>
                    <div>
                        <InputLabel for="facebook" value="Facebook" />
                        <TextInput id="facebook" v-model="form.facebook" class="mt-1 block w-full" dir="ltr" placeholder="username" />
                    </div>
                </div>
                <p class="mt-3 text-xs text-gray-400">{{ $t('settings.contact_hint') }}</p>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
                <h3 class="mb-4 font-medium text-gray-900 dark:text-gray-100">{{ $t('settings.wifi') }}</h3>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <InputLabel for="wifi_ssid" :value="$t('settings.wifi_ssid')" />
                        <TextInput id="wifi_ssid" v-model="form.wifi_ssid" class="mt-1 block w-full" dir="ltr" />
                    </div>
                    <div>
                        <InputLabel for="wifi_password" :value="$t('settings.wifi_password')" />
                        <TextInput id="wifi_password" v-model="form.wifi_password" class="mt-1 block w-full" dir="ltr" />
                    </div>
                </div>
                <p class="mt-3 text-xs text-gray-400">{{ $t('settings.wifi_hint') }}</p>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
                <h3 class="mb-4 font-medium text-gray-900 dark:text-gray-100">{{ $t('settings.receipt') }}</h3>

                <div>
                    <InputLabel for="receipt_footer_text" :value="$t('settings.receipt_footer')" />
                    <textarea
                        id="receipt_footer_text"
                        v-model="form.receipt_footer_text"
                        rows="3"
                        maxlength="500"
                        class="focus-brand mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                    ></textarea>
                    <InputError class="mt-2" :message="form.errors.receipt_footer_text" />
                </div>
                <p class="mt-3 text-xs text-gray-400">{{ $t('settings.receipt_footer_hint') }}</p>
            </section>

            <div class="flex justify-end">
                <PrimaryButton :disabled="form.processing">{{ $t('common.save') }}</PrimaryButton>
            </div>
        </form>
    </AuthenticatedLayout>
</template>
