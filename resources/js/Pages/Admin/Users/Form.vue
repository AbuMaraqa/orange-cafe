<script setup>
import { computed } from 'vue';
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
    user: {
        type: Object,
        default: null,
    },
    roles: {
        type: Array,
        required: true,
    },
});

const isEdit = computed(() => props.user !== null);

const form = useForm({
    name: props.user?.name ?? '',
    username: props.user?.username ?? '',
    password: '',
    role: props.user?.role ?? props.roles[0],
});

function submit() {
    if (isEdit.value) {
        form.put(route('admin.users.update', props.user.id));
    } else {
        form.post(route('admin.users.store'));
    }
}
</script>

<template>
    <Head :title="isEdit ? $t('admin.users.edit') : $t('admin.users.create')" />

    <AuthenticatedLayout>
        <template #header>{{ isEdit ? $t('admin.users.edit') : $t('admin.users.create') }}</template>

        <div class="mx-auto max-w-lg overflow-hidden rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
            <form @submit.prevent="submit" class="space-y-6">
                <div>
                    <InputLabel for="name" :value="$t('common.name')" />
                    <TextInput id="name" v-model="form.name" class="mt-1 block w-full" required autofocus />
                    <InputError class="mt-2" :message="form.errors.name" />
                </div>

                <div>
                    <InputLabel for="username" :value="$t('auth.username')" />
                    <TextInput id="username" v-model="form.username" class="mt-1 block w-full" required autocomplete="off" autocapitalize="none" spellcheck="false" />
                    <p class="mt-1 text-xs text-gray-500">{{ $t('admin.users.username_hint') }}</p>
                    <InputError class="mt-2" :message="form.errors.username" />
                </div>

                <div>
                    <InputLabel for="password" :value="$t('auth.password')" />
                    <TextInput id="password" v-model="form.password" type="password" class="mt-1 block w-full" dir="ltr" :required="!isEdit" autocomplete="new-password" />
                    <p v-if="isEdit" class="mt-1 text-xs text-gray-500">{{ $t('admin.users.password_hint') }}</p>
                    <InputError class="mt-2" :message="form.errors.password" />
                </div>

                <div>
                    <InputLabel for="role" :value="$t('admin.users.role')" />
                    <select
                        id="role"
                        v-model="form.role"
                        class="focus-brand mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                    >
                        <option v-for="role in roles" :key="role" :value="role">{{ t(`admin.users.roles.${role}`) }}</option>
                    </select>
                    <InputError class="mt-2" :message="form.errors.role" />
                </div>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <Link :href="route('admin.users.index')">
                        <SecondaryButton type="button">{{ $t('common.cancel') }}</SecondaryButton>
                    </Link>
                    <PrimaryButton :disabled="form.processing">{{ $t('common.save') }}</PrimaryButton>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
