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

const { t } = useI18n();

const props = defineProps({
    printer: {
        type: Object,
        default: null,
    },
});

const isEdit = computed(() => props.printer !== null);

const form = useForm({
    name: props.printer?.name ?? '',
    station: props.printer?.station ?? '',
    connection_type: props.printer?.connection_type ?? 'network',
    ip_address: props.printer?.ip_address ?? '',
    port: props.printer?.port ?? 9100,
    usb_vendor_id: props.printer?.usb_vendor_id ?? '',
    usb_product_id: props.printer?.usb_product_id ?? '',
    is_active: props.printer?.is_active ?? true,
});

// WebUSB (Chrome/Edge only, and only over HTTPS or localhost) lets the
// browser itself prompt for a connected USB device and hand back its
// vendor/product IDs — no more copying them out of `lsusb` by hand. No
// filters passed to requestDevice(): most cheap receipt printers use a
// vendor-specific USB class rather than the standard Printer class (see
// UsbPrinterTransport), so filtering by class would hide exactly the
// devices this is for.
const usbSupported = typeof navigator !== 'undefined' && 'usb' in navigator;
const detectingUsb = ref(false);
const usbDetectError = ref('');

async function detectUsbPrinter() {
    usbDetectError.value = '';
    detectingUsb.value = true;

    try {
        const device = await navigator.usb.requestDevice({ filters: [] });
        form.usb_vendor_id = device.vendorId.toString(16).padStart(4, '0');
        form.usb_product_id = device.productId.toString(16).padStart(4, '0');
        if (!form.name && device.productName) {
            form.name = device.productName;
        }
    } catch (error) {
        // NotFoundError just means the admin closed the picker without
        // choosing anything — not worth surfacing as an error.
        if (error?.name !== 'NotFoundError') {
            usbDetectError.value = t('printers.usb_detect_error');
        }
    } finally {
        detectingUsb.value = false;
    }
}

function submit() {
    if (isEdit.value) {
        form.put(route('admin.printers.update', props.printer.id));
    } else {
        form.post(route('admin.printers.store'));
    }
}
</script>

<template>
    <Head :title="isEdit ? $t('printers.edit') : $t('printers.create')" />

    <AuthenticatedLayout>
        <template #header>{{ isEdit ? $t('printers.edit') : $t('printers.create') }}</template>

        <div class="mx-auto max-w-lg overflow-hidden rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
            <form @submit.prevent="submit" class="space-y-6">
                <div>
                    <InputLabel for="name" :value="$t('common.name')" />
                    <TextInput id="name" v-model="form.name" class="mt-1 block w-full" required autofocus :placeholder="$t('printers.name_example')" />
                    <InputError class="mt-2" :message="form.errors.name" />
                </div>

                <div>
                    <InputLabel for="station" :value="$t('admin.categories.station')" />
                    <TextInput id="station" v-model="form.station" class="mt-1 block w-full" required :placeholder="$t('admin.categories.station_placeholder')" />
                    <p class="mt-1 text-xs text-gray-500">{{ $t('printers.station_hint') }}</p>
                    <InputError class="mt-2" :message="form.errors.station" />
                </div>

                <div>
                    <InputLabel for="connection_type" :value="$t('printers.connection_type')" />
                    <select
                        id="connection_type"
                        v-model="form.connection_type"
                        class="focus-brand mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                    >
                        <option value="network">{{ $t('printers.connection_network') }}</option>
                        <option value="usb">{{ $t('printers.connection_usb') }}</option>
                    </select>
                    <InputError class="mt-2" :message="form.errors.connection_type" />
                </div>

                <div v-if="form.connection_type === 'network'" class="grid grid-cols-3 gap-4">
                    <div class="col-span-2">
                        <InputLabel for="ip_address" :value="$t('printers.ip_address')" />
                        <TextInput id="ip_address" v-model="form.ip_address" class="mt-1 block w-full" required placeholder="192.168.1.50" />
                        <InputError class="mt-2" :message="form.errors.ip_address" />
                    </div>
                    <div>
                        <InputLabel for="port" :value="$t('printers.port')" />
                        <TextInput id="port" v-model.number="form.port" type="number" class="mt-1 block w-full" required />
                        <InputError class="mt-2" :message="form.errors.port" />
                    </div>
                </div>

                <div v-else class="grid grid-cols-2 gap-4">
                    <div class="col-span-2">
                        <SecondaryButton type="button" :disabled="!usbSupported || detectingUsb" @click="detectUsbPrinter">
                            {{ detectingUsb ? $t('printers.usb_detecting') : $t('printers.usb_detect') }}
                        </SecondaryButton>
                        <p v-if="!usbSupported" class="mt-1 text-xs text-amber-600 dark:text-amber-500">{{ $t('printers.usb_detect_unsupported') }}</p>
                        <p v-else-if="usbDetectError" class="mt-1 text-xs text-red-500">{{ usbDetectError }}</p>
                        <p v-else class="mt-1 text-xs text-gray-500">{{ $t('printers.usb_detect_hint') }}</p>
                    </div>
                    <div>
                        <InputLabel for="usb_vendor_id" :value="$t('printers.usb_vendor_id')" />
                        <TextInput id="usb_vendor_id" v-model="form.usb_vendor_id" class="mt-1 block w-full" required placeholder="154f" dir="ltr" />
                        <InputError class="mt-2" :message="form.errors.usb_vendor_id" />
                    </div>
                    <div>
                        <InputLabel for="usb_product_id" :value="$t('printers.usb_product_id')" />
                        <TextInput id="usb_product_id" v-model="form.usb_product_id" class="mt-1 block w-full" required placeholder="1001" dir="ltr" />
                        <InputError class="mt-2" :message="form.errors.usb_product_id" />
                    </div>
                    <p class="col-span-2 -mt-2 text-xs text-gray-500">{{ $t('printers.usb_hint') }}</p>
                </div>

                <label class="flex items-center">
                    <Checkbox v-model:checked="form.is_active" />
                    <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">{{ $t('common.active') }}</span>
                </label>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <Link :href="route('admin.printers.index')">
                        <SecondaryButton type="button">{{ $t('common.cancel') }}</SecondaryButton>
                    </Link>
                    <PrimaryButton :disabled="form.processing">{{ $t('common.save') }}</PrimaryButton>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
