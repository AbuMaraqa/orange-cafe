<script setup>
import Dropdown from '@/Components/Dropdown.vue';

/**
 * The one button — "+ إضافة عملية" — that reaches every entry in the
 * system in two taps.
 *
 * Which entries appear depends on what the person is allowed to do, so a
 * cashier is not offered a staff advance they cannot save. Hiding it is
 * better than showing it and failing on submit, which teaches people that
 * the app is unreliable rather than that they lack a permission.
 */
defineProps({
    can: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['expense', 'absence', 'collection', 'withdrawal']);
</script>

<template>
    <Dropdown align="end" width="56">
        <template #trigger>
            <button
                type="button"
                class="inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:opacity-90"
                style="background-color: var(--brand-primary)"
            >
                <span class="text-lg leading-none">+</span>
                {{ $t('accounting.quick_add') }}
            </button>
        </template>

        <template #content>
            <div class="py-1">
                <button
                    v-if="can.collect"
                    type="button"
                    class="flex w-full items-center gap-3 px-4 py-3 text-start text-sm text-gray-700 transition hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
                    @click="emit('collection')"
                >
                    <span class="text-lg">💰</span>{{ $t('accounting.add_collection') }}
                </button>
                <button
                    v-if="can.expense"
                    type="button"
                    class="flex w-full items-center gap-3 px-4 py-3 text-start text-sm text-gray-700 transition hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
                    @click="emit('expense')"
                >
                    <span class="text-lg">💸</span>{{ $t('accounting.add_expense') }}
                </button>
                <button
                    v-if="can.absence"
                    type="button"
                    class="flex w-full items-center gap-3 px-4 py-3 text-start text-sm text-gray-700 transition hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
                    @click="emit('absence')"
                >
                    <span class="text-lg">👤</span>{{ $t('accounting.add_absence') }}
                </button>
                <button
                    v-if="can.collect"
                    type="button"
                    class="flex w-full items-center gap-3 px-4 py-3 text-start text-sm text-gray-700 transition hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
                    @click="emit('withdrawal')"
                >
                    <span class="text-lg">🏦</span>{{ $t('accounting.add_movement') }}
                </button>
            </div>
        </template>
    </Dropdown>
</template>
