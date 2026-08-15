<script setup>
import { ref } from 'vue';
import Checkbox from '@/Components/Checkbox.vue';
import DangerButton from '@/Components/DangerButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import NavIcon from '@/Components/NavIcon.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    tables: {
        type: Array,
        required: true,
    },
    services: {
        type: Array,
        default: () => [],
    },
});

const canvas = ref(null);
const selected = ref(null);
const showCreate = ref(false);
const draggingId = ref(null);
let dragMoved = false;
const serviceQrId = ref('');

function serviceQrUrl(tableId, serviceId) {
    return route('admin.tables.service-qr', [tableId, serviceId]);
}

const createForm = useForm({ label: '', seats: 4, shape: 'square', pos_x: 50, pos_y: 50, is_active: true });
const editForm = useForm({ label: '', seats: 4, shape: 'square', pos_x: 0, pos_y: 0, is_active: true });

function statusClasses(table) {
    if (!table.is_occupied) {
        return 'border-gray-300 bg-white text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300';
    }
    if (table.active_order?.is_paid) {
        return 'border-teal-500 bg-teal-50 text-teal-700 dark:bg-teal-900/30 dark:text-teal-300';
    }
    return 'border-amber-500 bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300';
}

// Chairs drawn around the table's perimeter, one per seat — same visual
// as the waiter/cashier floor plan (see TableFloorPlan.vue) so a table
// looks identical here and there, just editable in this view.
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

function selectTable(table) {
    if (dragMoved) {
        dragMoved = false;
        return;
    }
    selected.value = table;
    serviceQrId.value = '';
    editForm.label = table.label;
    editForm.seats = table.seats;
    editForm.shape = table.shape;
    editForm.pos_x = table.pos_x;
    editForm.pos_y = table.pos_y;
    editForm.is_active = table.is_active;
}

function startDrag(table, event) {
    draggingId.value = table.id;
    dragMoved = false;
    event.preventDefault();

    const onMove = (moveEvent) => {
        const rect = canvas.value.getBoundingClientRect();
        const point = moveEvent.touches ? moveEvent.touches[0] : moveEvent;
        const x = Math.min(100, Math.max(0, ((point.clientX - rect.left) / rect.width) * 100));
        const y = Math.min(100, Math.max(0, ((point.clientY - rect.top) / rect.height) * 100));
        dragMoved = true;
        table.pos_x = x;
        table.pos_y = y;
    };

    const onUp = () => {
        window.removeEventListener('pointermove', onMove);
        window.removeEventListener('pointerup', onUp);
        draggingId.value = null;

        if (dragMoved) {
            router.patch(route('admin.tables.position', table.id), { pos_x: table.pos_x, pos_y: table.pos_y }, { preserveScroll: true, preserveState: true });
        }
    };

    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onUp);
}

function submitCreate() {
    createForm.post(route('admin.tables.store'), {
        preserveScroll: true,
        onSuccess: () => {
            showCreate.value = false;
            createForm.reset();
        },
    });
}

function submitEdit() {
    editForm.patch(route('admin.tables.update', selected.value.id), {
        preserveScroll: true,
        onSuccess: () => (selected.value = null),
    });
}

function destroy() {
    if (!confirm(`${selected.value.label}: ${t('common.are_you_sure')}`)) return;

    router.delete(route('admin.tables.destroy', selected.value.id), {
        preserveScroll: true,
        onSuccess: () => (selected.value = null),
    });
}

function formatPrice(cents) {
    return (cents / 100).toFixed(2);
}
</script>

<template>
    <Head :title="$t('tables.title')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('tables.title') }}</template>

        <div class="mb-4 flex items-center justify-between">
            <p class="text-sm text-gray-500">{{ $t('tables.drag_hint') }} · {{ $t('tables.click_hint') }}</p>
            <div class="flex gap-2">
                <a :href="route('admin.tables.qr-all')" target="_blank" rel="noopener">
                    <SecondaryButton>{{ $t('tables.print_all_qr') }}</SecondaryButton>
                </a>
                <PrimaryButton @click="showCreate = true">{{ $t('tables.add_table') }}</PrimaryButton>
            </div>
        </div>

        <div class="flex gap-6">
            <div
                ref="canvas"
                class="relative h-[32rem] flex-1 rounded-lg border-2 border-dashed border-gray-300 bg-gray-50 dark:border-gray-700 dark:bg-gray-900/50"
            >
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
                        :style="{ cursor: draggingId === table.id ? 'grabbing' : 'grab' }"
                        :title="table.active_order?.customer_name ?? null"
                        @pointerdown="startDrag(table, $event)"
                        @click="selectTable(table)"
                    >
                        {{ table.label }}
                        <span v-if="table.is_occupied" class="inline-flex items-center gap-0.5 text-[10px] font-normal">
                            <NavIcon name="clipboardList" class="h-2.5 w-2.5" />{{ table.active_order.items_count }}
                        </span>
                        <span v-if="table.active_order?.customer_name" class="max-w-[3.25rem] truncate text-[8px] font-normal leading-tight opacity-80">
                            {{ table.active_order.customer_name }}
                        </span>
                    </button>
                </div>
            </div>

            <!-- Detail / edit panel -->
            <div v-if="selected" class="w-80 shrink-0 rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ selected.label }}</h3>
                    <div class="flex items-center gap-2">
                        <a :href="route('admin.tables.qr', selected.id)" target="_blank" rel="noopener" class="text-xs text-gray-500 underline hover:text-gray-800 dark:hover:text-gray-200">
                            {{ $t('tables.print_qr') }}
                        </a>
                        <button type="button" class="text-gray-400 hover:text-gray-600" @click="selected = null">
                            <NavIcon name="close" class="h-4 w-4" />
                        </button>
                    </div>
                </div>

                <!-- Per-service QR: a table can also print a sticker for one
                     specific service (e.g. "charcoal" on a shisha table) —
                     scanning it fires that request straight away instead of
                     opening the full menu first. -->
                <div v-if="services.length > 0" class="mb-5 flex items-center gap-2">
                    <select
                        v-model="serviceQrId"
                        class="focus-brand min-w-0 flex-1 rounded-md border-gray-300 py-1.5 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                    >
                        <option value="">{{ $t('tables.print_service_qr') }}</option>
                        <option v-for="service in services" :key="service.id" :value="service.id">
                            {{ service.icon }} {{ service.name }}
                        </option>
                    </select>
                    <a
                        v-if="serviceQrId"
                        :href="serviceQrUrl(selected.id, serviceQrId)"
                        target="_blank"
                        rel="noopener"
                        class="shrink-0 text-xs text-gray-500 underline hover:text-gray-800 dark:hover:text-gray-200"
                    >
                        {{ $t('tables.print_qr') }}
                    </a>
                </div>

                <div v-if="selected.is_occupied" class="mb-5 rounded-md bg-gray-50 p-3 text-sm dark:bg-gray-800/60">
                    <div class="flex items-center justify-between">
                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ selected.active_order.reference }}</span>
                        <span
                            class="rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="selected.active_order.is_paid
                                ? 'bg-teal-100 text-teal-700 dark:bg-teal-900/40 dark:text-teal-300'
                                : 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'"
                        >
                            {{ selected.active_order.is_paid ? $t('tables.paid') : $t('tables.unpaid') }}
                        </span>
                    </div>
                    <p v-if="selected.active_order.customer_name" class="mt-1 font-medium text-gray-900 dark:text-gray-100">{{ selected.active_order.customer_name }}</p>
                    <p class="mt-1 text-gray-500">{{ $t('tables.guests') }}: {{ selected.active_order.guest_count ?? '—' }}</p>
                    <p class="mt-1 text-gray-500">{{ $t('common.status') }}: {{ selected.active_order.status }}</p>
                    <p class="mt-1 font-medium text-gray-900 dark:text-gray-100">{{ formatPrice(selected.active_order.total_cents) }}</p>
                </div>
                <p v-else class="mb-5 text-sm text-gray-500">{{ $t('tables.empty') }}</p>

                <form @submit.prevent="submitEdit" class="space-y-4">
                    <div>
                        <InputLabel for="edit-label" :value="$t('tables.label')" />
                        <TextInput id="edit-label" v-model="editForm.label" class="mt-1 block w-full" required />
                        <InputError class="mt-1" :message="editForm.errors.label" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <InputLabel for="edit-seats" :value="$t('tables.seats')" />
                            <TextInput id="edit-seats" v-model.number="editForm.seats" type="number" min="1" class="mt-1 block w-full" />
                        </div>
                        <div>
                            <InputLabel for="edit-shape" :value="$t('tables.shape')" />
                            <select
                                id="edit-shape"
                                v-model="editForm.shape"
                                class="focus-brand mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                            >
                                <option value="square">{{ $t('tables.square') }}</option>
                                <option value="round">{{ $t('tables.round') }}</option>
                            </select>
                        </div>
                    </div>
                    <label class="flex items-center">
                        <Checkbox v-model:checked="editForm.is_active" />
                        <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">{{ $t('common.active') }}</span>
                    </label>
                    <div class="flex justify-between pt-2">
                        <DangerButton type="button" @click="destroy">{{ $t('common.delete') }}</DangerButton>
                        <PrimaryButton :disabled="editForm.processing">{{ $t('common.save') }}</PrimaryButton>
                    </div>
                </form>
            </div>
        </div>

        <!-- Create modal (simple inline panel) -->
        <div v-if="showCreate" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/40 p-4" @click.self="showCreate = false">
            <div class="w-full max-w-sm rounded-lg bg-white p-6 dark:bg-gray-900">
                <h3 class="mb-4 font-semibold text-gray-900 dark:text-gray-100">{{ $t('tables.add_table') }}</h3>
                <form @submit.prevent="submitCreate" class="space-y-4">
                    <div>
                        <InputLabel for="new-label" :value="$t('tables.label')" />
                        <TextInput id="new-label" v-model="createForm.label" class="mt-1 block w-full" required autofocus />
                        <InputError class="mt-1" :message="createForm.errors.label" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <InputLabel for="new-seats" :value="$t('tables.seats')" />
                            <TextInput id="new-seats" v-model.number="createForm.seats" type="number" min="1" class="mt-1 block w-full" />
                        </div>
                        <div>
                            <InputLabel for="new-shape" :value="$t('tables.shape')" />
                            <select
                                id="new-shape"
                                v-model="createForm.shape"
                                class="focus-brand mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"
                            >
                                <option value="square">{{ $t('tables.square') }}</option>
                                <option value="round">{{ $t('tables.round') }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 pt-2">
                        <SecondaryButton type="button" @click="showCreate = false">{{ $t('common.cancel') }}</SecondaryButton>
                        <PrimaryButton :disabled="createForm.processing">{{ $t('common.save') }}</PrimaryButton>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
