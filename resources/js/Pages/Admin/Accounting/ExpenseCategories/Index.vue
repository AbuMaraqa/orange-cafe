<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * "أكتب فقط: صيانة ثم حفظ" — one box at the top, one row per category, and
 * editing happens in place. No create page, no edit page.
 */
defineProps({
    categories: { type: Array, required: true },
});

const { t } = useI18n();

const form = useForm({ name: '' });
const editingId = ref(null);
const editName = ref('');

function add() {
    form.post(route('admin.accounting.expense-categories.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function startEdit(category) {
    editingId.value = category.id;
    editName.value = category.name;
}

function saveEdit(category) {
    router.patch(route('admin.accounting.expense-categories.update', category.id), {
        name: editName.value,
        is_active: category.is_active,
    }, {
        preserveScroll: true,
        onSuccess: () => { editingId.value = null; },
    });
}

function toggleActive(category) {
    router.patch(route('admin.accounting.expense-categories.update', category.id), {
        name: category.name,
        is_active: !category.is_active,
    }, { preserveScroll: true });
}

function destroy(category) {
    if (!confirm(t('accounting.confirm_delete'))) return;

    router.delete(route('admin.accounting.expense-categories.destroy', category.id), { preserveScroll: true });
}
</script>

<template>
    <Head :title="$t('accounting.expense_categories')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('accounting.expense_categories') }}</template>

        <div class="mb-4">
            <Link :href="route('admin.accounting.expenses.index')"
                  class="text-sm text-gray-500 underline hover:text-gray-800 dark:hover:text-gray-200">
                ← {{ $t('accounting.expenses') }}
            </Link>
        </div>

        <form class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900"
              @submit.prevent="add">
            <div class="min-w-56 flex-1">
                <label for="new-category" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    {{ $t('accounting.category_name') }}
                </label>
                <TextInput id="new-category" v-model="form.name" required class="mt-1 block w-full"
                           :placeholder="$t('accounting.category_placeholder')" />
                <InputError class="mt-1" :message="form.errors.name" />
            </div>
            <PrimaryButton :disabled="form.processing">+ {{ $t('accounting.new_category') }}</PrimaryButton>
        </form>

        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                <thead class="bg-gray-50 dark:bg-gray-900/60">
                    <tr class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-3 text-start">{{ $t('accounting.category_name') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('accounting.used_in') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('accounting.active') }}</th>
                        <th class="px-4 py-3 text-end">{{ $t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                    <tr v-for="category in categories" :key="category.id">
                        <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">
                            <TextInput v-if="editingId === category.id" v-model="editName"
                                       class="block w-full text-sm" @keyup.enter="saveEdit(category)" />
                            <span v-else>{{ category.name }}</span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-500">
                            {{ category.expenses_count }} {{ $t('accounting.records') }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <Checkbox :checked="category.is_active" @update:checked="toggleActive(category)" />
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-end text-sm">
                            <div class="flex items-center justify-end gap-3">
                                <template v-if="editingId === category.id">
                                    <button type="button" class="font-medium" style="color: var(--brand-primary)"
                                            @click="saveEdit(category)">{{ $t('accounting.save') }}</button>
                                    <button type="button" class="text-gray-400"
                                            @click="editingId = null">{{ $t('common.cancel') }}</button>
                                </template>
                                <template v-else>
                                    <button type="button" class="text-gray-500 hover:text-gray-800 dark:hover:text-gray-200"
                                            @click="startEdit(category)">{{ $t('common.edit') }}</button>
                                    <button v-if="category.can_delete" type="button" class="text-red-600 hover:text-red-800"
                                            @click="destroy(category)">{{ $t('common.delete') }}</button>
                                    <!-- Explained rather than just disabled: a
                                         greyed-out button teaches nothing. -->
                                    <span v-else class="text-xs text-gray-400">{{ $t('accounting.cannot_delete_in_use') }}</span>
                                </template>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AuthenticatedLayout>
</template>
