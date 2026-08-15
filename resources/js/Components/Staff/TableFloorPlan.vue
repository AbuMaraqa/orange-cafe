<script setup>
import NavIcon from '@/Components/NavIcon.vue';

defineProps({
    tables: {
        type: Array,
        required: true,
    },
});

const emit = defineEmits(['select']);

// Same status coloring as the admin floor-plan editor (Admin/Tables/Index.vue)
// so a table reads identically everywhere it appears — gray when free, amber
// while an order is running and unpaid, teal once it's settled.
function statusClasses(table) {
    if (!table.active_order) {
        return 'border-gray-300 bg-white text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300';
    }
    if (table.active_order.is_paid) {
        return 'border-teal-500 bg-teal-50 text-teal-700 dark:bg-teal-900/30 dark:text-teal-300';
    }
    return 'border-amber-500 bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300';
}

// Chairs drawn around the table's perimeter, one per seat, evenly spaced
// by angle — works the same for round and square tables since the chairs
// sit outside the tile's own shape regardless. Capped at 8 so a
// large-capacity table doesn't turn into a ring of clutter at this size.
function chairs(table) {
    const count = Math.max(0, Math.min(table.seats ?? 4, 8));

    return Array.from({ length: count }, (_, i) => {
        const angle = (i / count) * 2 * Math.PI - Math.PI / 2;

        return {
            left: `${50 + Math.cos(angle) * 66}%`,
            top: `${50 + Math.sin(angle) * 66}%`,
        };
    });
}
</script>

<template>
    <div class="relative h-[32rem] w-full rounded-lg border-2 border-dashed border-gray-300 bg-gray-50 dark:border-gray-700 dark:bg-gray-900/50">
        <div
            v-for="table in tables"
            :key="table.id"
            class="absolute h-16 w-16 -translate-x-1/2 -translate-y-1/2"
            :style="{ left: table.pos_x + '%', top: table.pos_y + '%' }"
        >
            <span
                v-for="(pos, i) in chairs(table)"
                :key="i"
                class="pointer-events-none absolute h-2 w-3 -translate-x-1/2 -translate-y-1/2 rounded-[3px] border border-gray-300 bg-gray-100 dark:border-gray-600 dark:bg-gray-700"
                :style="{ left: pos.left, top: pos.top }"
            ></span>

            <button
                type="button"
                class="absolute inset-0 flex flex-col items-center justify-center border-2 text-xs font-semibold shadow-sm transition-transform hover:z-10 hover:scale-105"
                :class="[statusClasses(table), table.shape === 'round' ? 'rounded-full' : 'rounded-md']"
                :title="table.active_order?.customer_name ?? null"
                @click="emit('select', table)"
            >
                {{ table.label }}
                <span v-if="table.active_order" class="inline-flex items-center gap-0.5 text-[10px] font-normal leading-tight">
                    <NavIcon name="clipboardList" class="h-2.5 w-2.5" />{{ table.active_order.items_count }}
                </span>
                <span v-if="table.active_order?.customer_name" class="max-w-[3.25rem] truncate text-[8px] font-normal leading-tight opacity-80">
                    {{ table.active_order.customer_name }}
                </span>
            </button>
        </div>
    </div>
</template>
