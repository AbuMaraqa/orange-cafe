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
    // Whether the *server* can drive a USB printer, which is a separate
    // question from whether this browser can detect one (usbSupported
    // below). The packaged desktop app's PHP runtime has no FFI extension,
    // so USB printers there would save fine and then never print.
    usbTransportSupported: {
        type: Boolean,
        default: true,
    },
    stations: {
        type: Array,
        default: () => [],
    },
    /** Address carried over from a discovered printer on the index screen. */
    prefill: {
        type: Object,
        default: () => ({}),
    },
});

const isEdit = computed(() => props.printer !== null);

const form = useForm({
    name: props.printer?.name ?? '',
    station: props.printer?.station ?? '',
    connection_type: props.printer?.connection_type ?? 'network',
    ip_address: props.printer?.ip_address ?? props.prefill?.ip_address ?? '',
    port: props.printer?.port ?? props.prefill?.port ?? 9100,
    usb_vendor_id: props.printer?.usb_vendor_id ?? '',
    usb_product_id: props.printer?.usb_product_id ?? '',
    windows_share: props.printer?.windows_share ?? '',
    is_active: props.printer?.is_active ?? true,
});

// WebUSB (Chrome/Edge only, and only over HTTPS or localhost) lets the
// browser itself prompt for a connected USB device and hand back its
// vendor/product IDs — no more copying them out of `lsusb` by hand. No
// filters passed to requestDevice(): most cheap receipt printers use a
// vendor-specific USB class rather than the standard Printer class (see
// UsbPrinterTransport), so filtering by class would hide exactly the
// devices this is for.
// A station typed before this became a list still has to be editable, so
// the box stays available and is pre-opened when the saved value is not one
// of the offered options.
const customStation = ref(
    Boolean(props.printer?.station) && !props.stations.some((s) => s.value === props.printer.station)
);

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
                    <select
                        v-if="!customStation"
                        id="station"
                        v-model="form.station"
                        class="focus-brand mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                        required
                    >
                        <option value="" disabled>— اختر المحطة —</option>
                        <option v-for="station in stations" :key="station.value" :value="station.value">
                            {{ station.label }}
                        </option>
                    </select>

                    <TextInput
                        v-else
                        id="station"
                        v-model="form.station"
                        class="mt-1 block w-full"
                        dir="ltr"
                        required
                        :placeholder="$t('admin.categories.station_placeholder')"
                    />

                    <button
                        type="button"
                        class="mt-1 text-xs underline"
                        style="color: var(--brand-primary)"
                        @click="customStation = !customStation"
                    >
                        {{ customStation ? 'اختيار من المحطات الموجودة' : 'كتابة محطة جديدة يدوياً' }}
                    </button>

                    <p v-if="form.station === 'cashier'" class="mt-1 text-xs text-gray-500">
                        هذه الطابعة ستطبع الفواتير وتفتح درج النقود، وتستقبل تذاكر المطبخ إذا تعذّرت طابعة المطبخ.
                    </p>
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
                        <option value="windows">طابعة ويندوز (مشاركة) — تعمل مع USB</option>
                        <option value="usb">{{ $t('printers.connection_usb') }}</option>
                    </select>
                    <InputError class="mt-2" :message="form.errors.connection_type" />
                </div>

                <div v-if="form.connection_type === 'windows'" class="space-y-4">
                    <div>
                        <InputLabel for="windows_share" value="اسم مشاركة الطابعة في ويندوز" />
                        <TextInput id="windows_share" v-model="form.windows_share" class="mt-1 block w-full" dir="ltr" placeholder="CASHIER" required />
                        <InputError class="mt-2" :message="form.errors.windows_share" />
                    </div>

                    <div>
                        <InputLabel for="windows_host" value="اسم الجهاز على الشبكة (اختياري)" />
                        <TextInput id="windows_host" v-model="form.ip_address" class="mt-1 block w-full" dir="ltr" placeholder="اتركه فارغاً إذا الطابعة موصولة بهذا الجهاز" />
                        <InputError class="mt-2" :message="form.errors.ip_address" />
                    </div>

                    <div class="rounded-md border border-blue-200 bg-blue-50 p-3 text-xs leading-relaxed text-blue-900 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-200">
                        <p class="mb-1 font-semibold">هذا الخيار هو الحل لطابعات USB.</p>
                        <p>
                            ويندوز عنده تعريف الطابعة أصلاً، فالتطبيق بيرسل الطباعة عن طريقه بدل ما يتصل
                            بالـ USB مباشرة. للتفعيل على الجهاز الموصولة فيه الطابعة:
                        </p>
                        <p class="mt-1">
                            الإعدادات ← الأجهزة والطابعات ← كبسة يمين على الطابعة ← Printer properties ←
                            تبويب Sharing ← فعّل <span dir="ltr">Share this printer</span> ← اكتب اسم
                            مشاركة بدون مسافات (مثلاً <span dir="ltr">CASHIER</span>) واكتبه هنا فوق.
                        </p>
                    </div>
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

                <!-- Explicitly the USB case, not "anything that is not
                     network": with v-else this block also appeared for the
                     Windows-share option, so a till printer configured
                     through the spooler still showed USB vendor/product
                     fields and the warning saying USB is impossible —
                     directly contradicting the option the user had just
                     chosen to solve exactly that. -->
                <div v-if="form.connection_type === 'usb'" class="grid grid-cols-2 gap-4">
                    <!-- Shown above the fields, not beside them: on the
                         packaged desktop app nothing entered below can ever
                         print, so it has to be read before the form is
                         filled in rather than after. -->
                    <p
                        v-if="!usbTransportSupported"
                        class="col-span-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-300"
                    >
                        {{ $t('printers.usb_unsupported_server') }}
                    </p>
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
