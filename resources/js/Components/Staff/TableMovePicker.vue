<script setup>
import NavIcon from '@/Components/NavIcon.vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    tables: {
        type: Array,
        required: true,
    },
    currentTableId: {
        type: [Number, String],
        default: null,
    },
    orderId: {
        type: [Number, String],
        required: true,
    },
});

const emit = defineEmits(['close', 'moved']);
const { t } = useI18n();

function pick(table) {
    if (table.active_order && !confirm(t('tables.move_merge_confirm', { label: table.label }))) {
        return;
    }

    router.patch(route('orders.move-table', props.orderId), { table_id: table.id }, {
        preserveScroll: true,
        onSuccess: () => emit('moved'),
    });
}
</script>

<template>
    <div class="fixed inset-0 z-[60] flex items-center justify-center bg-gray-900/50 p-4" @click.self="emit('close')">
        <div class="w-full max-w-sm rounded-lg bg-white p-5 dark:bg-gray-900">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ t('tables.move_to') }}</h3>
                <button type="button" class="text-gray-400 hover:text-gray-600" @click="emit('close')">
                    <NavIcon name="close" class="h-4 w-4" />
                </button>
            </div>

            <div class="grid max-h-80 grid-cols-3 gap-2 overflow-y-auto">
                <button
                    v-for="table in tables.filter((tbl) => tbl.id !== currentTableId)"
                    :key="table.id"
                    type="button"
                    class="flex flex-col items-center justify-center rounded-md border-2 px-2 py-3 text-sm font-semibold"
                    :class="table.active_order
                        ? 'border-amber-400 bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300'
                        : 'border-gray-200 text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800'"
                    @click="pick(table)"
                >
                    {{ table.label }}
                    <span v-if="table.active_order" class="mt-0.5 text-center text-[10px] font-normal leading-tight">{{ t('tables.will_merge') }}</span>
                </button>
            </div>
        </div>
    </div>
</template>
