<script setup>
import AbsenceModal from '@/Components/Accounting/AbsenceModal.vue';
import StatCard from '@/Components/Accounting/StatCard.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatDate, useMoney } from '@/composables/useMoney';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    absences: { type: Array, required: true },
    filters: { type: Object, required: true },
    categories: { type: Array, required: true },
    staff: { type: Array, required: true },
    stats: { type: Object, required: true },
    byEmployee: { type: Array, required: true },
    today: { type: String, required: true },
});

const { t } = useI18n();
const { money } = useMoney();

const showModal = ref(false);
const editing = ref(null);

function reload(overrides = {}) {
    const pick = (key, fallback) => (key in overrides ? overrides[key] : fallback);

    const params = {
        from: pick('from', props.filters.from),
        to: pick('to', props.filters.to),
        category_id: pick('category_id', props.filters.category_id),
        user_id: pick('user_id', props.filters.user_id),
    };

    for (const key of Object.keys(params)) {
        if (params[key] === null || params[key] === '' || params[key] === undefined) delete params[key];
    }

    router.get(route('admin.accounting.absences.index'), params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function openNew() {
    editing.value = null;
    showModal.value = true;
}

function openEdit(absence) {
    editing.value = absence;
    showModal.value = true;
}

function destroy(absence) {
    if (!confirm(t('accounting.confirm_delete'))) return;

    router.delete(route('admin.accounting.absences.destroy', absence.id), { preserveScroll: true });
}

const selectClass = 'focus-brand block w-full rounded-md border-gray-300 py-1.5 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300';
</script>

<template>
    <Head :title="$t('accounting.absences')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('accounting.absences') }}</template>

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <Link
                :href="route('admin.accounting.absence-categories.index')"
                class="text-sm text-gray-500 underline hover:text-gray-800 dark:hover:text-gray-200"
            >{{ $t('accounting.absence_categories') }}</Link>

            <PrimaryButton type="button" @click="openNew">+ {{ $t('accounting.new_absence') }}</PrimaryButton>
        </div>

        <div class="grid grid-cols-3 gap-3">
            <StatCard :label="$t('accounting.absences_count')" :value="String(stats.range_count)" tone="neutral" icon="👤" />
            <StatCard :label="$t('accounting.total_deductions')" :value="money(stats.range_deductions_cents)" tone="warn" icon="🏷️" />
            <StatCard :label="$t('accounting.today_total')" :value="String(stats.today_count)" tone="neutral" />
        </div>

        <div class="mt-4 rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <TextInput :model-value="filters.from" type="date" dir="ltr" class="text-sm"
                           @update:model-value="(value) => reload({ from: value })" />
                <TextInput :model-value="filters.to" type="date" dir="ltr" class="text-sm"
                           @update:model-value="(value) => reload({ to: value })" />

                <select :class="selectClass" :value="filters.category_id ?? ''"
                        @change="reload({ category_id: $event.target.value || null })">
                    <option value="">{{ $t('accounting.all_categories') }}</option>
                    <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                </select>

                <select :class="selectClass" :value="filters.user_id ?? ''"
                        @change="reload({ user_id: $event.target.value || null })">
                    <option value="">{{ $t('accounting.all_staff') }}</option>
                    <option v-for="member in staff" :key="member.id" :value="member.id">{{ member.name }}</option>
                </select>
            </div>
        </div>

        <div v-if="byEmployee.length" class="mt-4 flex flex-wrap gap-2">
            <Link v-for="row in byEmployee" :key="row.id" :href="route('admin.accounting.staff.show', row.id)"
                  class="inline-flex items-center gap-2 rounded-full bg-gray-100 px-3 py-1 text-xs hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700">
                <span class="font-medium text-gray-700 dark:text-gray-300">{{ row.name }}</span>
                <span class="text-gray-500">{{ row.count }}</span>
                <span class="tabular-nums text-amber-600 dark:text-amber-400" dir="ltr">{{ money(row.deductions_cents) }}</span>
            </Link>
        </div>

        <div class="mt-4 overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                <thead class="bg-gray-50 dark:bg-gray-900/60">
                    <tr class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-3 text-start">{{ $t('accounting.date') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('accounting.staff_member') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('accounting.absence_type') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('accounting.deduction') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('accounting.reason') }}</th>
                        <th class="px-4 py-3 text-start">{{ $t('accounting.recorded_by') }}</th>
                        <th class="px-4 py-3 text-end">{{ $t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                    <tr v-if="absences.length === 0">
                        <td colspan="7" class="px-4 py-10 text-center text-sm text-gray-400">
                            {{ $t('accounting.no_absences') }}
                        </td>
                    </tr>
                    <tr v-for="absence in absences" :key="absence.id">
                        <td class="whitespace-nowrap px-4 py-3 text-sm tabular-nums text-gray-600 dark:text-gray-400" dir="ltr">
                            {{ formatDate(absence.absent_on) }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm">
                            <Link :href="route('admin.accounting.staff.show', absence.user_id)"
                                  class="font-medium" style="color: var(--brand-primary)">{{ absence.user_name }}</Link>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                            {{ absence.category_name }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm font-semibold tabular-nums" dir="ltr"
                            :class="absence.deduction_cents > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-400'">
                            {{ money(absence.deduction_cents) }}
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{{ absence.reason ?? '—' }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-xs text-gray-500">{{ absence.recorded_by_name ?? '—' }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-end text-sm">
                            <div class="flex items-center justify-end gap-3">
                                <button type="button" class="text-gray-500 hover:text-gray-800 dark:hover:text-gray-200"
                                        @click="openEdit(absence)">{{ $t('common.edit') }}</button>
                                <button type="button" class="text-red-600 hover:text-red-800"
                                        @click="destroy(absence)">{{ $t('common.delete') }}</button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <AbsenceModal
            :show="showModal"
            :categories="categories"
            :staff="staff"
            :absence="editing"
            :today="today"
            @close="showModal = false"
        />
    </AuthenticatedLayout>
</template>
