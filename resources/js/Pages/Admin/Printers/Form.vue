<script setup>
import { computed } from 'vue';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

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
    ip_address: props.printer?.ip_address ?? '',
    port: props.printer?.port ?? 9100,
    is_active: props.printer?.is_active ?? true,
});

function submit() {
    if (isEdit.value) {
        form.put(route('admin.printers.update', props.printer.id));
    } else {
        form.post(route('admin.printers.store'));
    }
}
</script>

<template>
    <Head :title="isEdit ? $t('admin.printers.edit') : $t('admin.printers.create')" />

    <AuthenticatedLayout>
        <template #header>{{ isEdit ? $t('admin.printers.edit') : $t('admin.printers.create') }}</template>

        <div class="mx-auto max-w-lg overflow-hidden rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
            <form @submit.prevent="submit" class="space-y-6">
                <div>
                    <InputLabel for="name" :value="$t('common.name')" />
                    <TextInput id="name" v-model="form.name" class="mt-1 block w-full" required autofocus :placeholder="$t('admin.printers.name_example')" />
                    <InputError class="mt-2" :message="form.errors.name" />
                </div>

                <div>
                    <InputLabel for="station" :value="$t('admin.categories.station')" />
                    <TextInput id="station" v-model="form.station" class="mt-1 block w-full" required :placeholder="$t('admin.categories.station_placeholder')" />
                    <p class="mt-1 text-xs text-gray-500">{{ $t('admin.printers.station_hint') }}</p>
                    <InputError class="mt-2" :message="form.errors.station" />
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <div class="col-span-2">
                        <InputLabel for="ip_address" :value="$t('admin.printers.ip_address')" />
                        <TextInput id="ip_address" v-model="form.ip_address" class="mt-1 block w-full" required placeholder="192.168.1.50" />
                        <InputError class="mt-2" :message="form.errors.ip_address" />
                    </div>
                    <div>
                        <InputLabel for="port" :value="$t('admin.printers.port')" />
                        <TextInput id="port" v-model.number="form.port" type="number" class="mt-1 block w-full" required />
                        <InputError class="mt-2" :message="form.errors.port" />
                    </div>
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
