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
const serviceQrId = ref('');

// Arranging a dining room means lining tables up in rows, so positions snap
// to a grid by default. Hold Alt while dragging (or switch this off) for
// free placement — an awkward corner table sometimes needs it.
const snapToGrid = ref(true);
const GRID_PERCENT = 2.5;

// A drag has to travel this far before it counts as a drag rather than a
// click. Without it, the one-pixel tremor of an ordinary click registered as
// a move, so tapping a table to open its panel frequently did nothing at all
// — and on a touchscreen, where a finger never holds perfectly still, it was
// close to unusable.
const DRAG_THRESHOLD_PX = 4;

// Half the table's own size as a percentage of the canvas, so a table can be
// pushed right up to the edge but never half-way off it. Measured rather than
// hardcoded: the canvas is fluid and the table is a fixed 4rem.
function halfSizePercent() {
    const rect = canvas.value?.getBoundingClientRect();

    if (!rect) return { x: 0, y: 0 };

    const size = 64; // h-16 / w-16
    return { x: (size / 2 / rect.width) * 100, y: (size / 2 / rect.height) * 100 };
}

function clampPosition(x, y) {
    const half = halfSizePercent();

    return {
        x: Math.min(100 - half.x, Math.max(half.x, x)),
        y: Math.min(100 - half.y, Math.max(half.y, y)),
    };
}

function applySnap(value, enabled) {
    return enabled ? Math.round(value / GRID_PERCENT) * GRID_PERCENT : value;
}

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

// Set by a drag that passed DRAG_THRESHOLD_PX, so the click that always
// follows pointerup doesn't also open the detail panel.
let suppressClick = false;

function selectTable(table) {
    if (suppressClick) {
        suppressClick = false;
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
    // A stray second pointerdown while a drag is already tracked (e.g. a
    // trackpad/touch quirk re-firing before the previous gesture's
    // pointerup) must never stack a second set of window listeners on top
    // of the first — that's what was causing a table to keep sliding and
    // re-firing the position PATCH after the finger/mouse was already
    // released, since two independent listener sets kept driving it.
    if (draggingId.value !== null) return;

    draggingId.value = table.id;
    event.preventDefault();

    // Pointer capture ties every subsequent move/up event for this
    // gesture to the element that started it, regardless of what the
    // pointer ends up over — without it, moving fast enough to leave the
    // table (or the canvas) can cause the browser to lose track of the
    // gesture and never deliver a pointerup at all.
    event.currentTarget?.setPointerCapture?.(event.pointerId);

    const rect = canvas.value.getBoundingClientRect();
    const origin = { x: table.pos_x, y: table.pos_y };
    const startPoint = { x: event.clientX, y: event.clientY };

    // Where inside the table the grab happened, so it moves *with* the
    // pointer instead of teleporting its centre under it. Grabbing a table
    // by its edge used to snap it sideways before it had moved at all,
    // which is what made placing one feel like a fight.
    const grabOffset = {
        x: origin.x - ((event.clientX - rect.left) / rect.width) * 100,
        y: origin.y - ((event.clientY - rect.top) / rect.height) * 100,
    };

    let moved = false;
    let frame = null;
    let pending = null;

    const commitFrame = () => {
        frame = null;

        if (!pending) return;

        table.pos_x = pending.x;
        table.pos_y = pending.y;
        pending = null;
    };

    const onMove = (moveEvent) => {
        if (moveEvent.pointerId !== event.pointerId) return;

        if (!moved) {
            const travelled = Math.hypot(moveEvent.clientX - startPoint.x, moveEvent.clientY - startPoint.y);

            if (travelled < DRAG_THRESHOLD_PX) return;

            moved = true;
        }

        // Alt bypasses snapping for the one table that has to sit off-grid.
        const snap = snapToGrid.value && !moveEvent.altKey;

        const raw = clampPosition(
            ((moveEvent.clientX - rect.left) / rect.width) * 100 + grabOffset.x,
            ((moveEvent.clientY - rect.top) / rect.height) * 100 + grabOffset.y,
        );

        pending = clampPosition(applySnap(raw.x, snap), applySnap(raw.y, snap));

        // Pointer events can fire several times per frame; writing reactive
        // state on each one makes Vue re-render faster than the screen can
        // show, which is where the dragging judder came from.
        frame ??= requestAnimationFrame(commitFrame);
    };

    // pointercancel (not just pointerup) must also end the gesture and
    // clean up — the browser fires this instead of pointerup when it
    // takes over the gesture itself (e.g. a scroll/refresh gesture on
    // touch), and skipping it here was exactly how listeners were left
    // dangling for the next drag to stack on top of.
    const endDrag = (endEvent) => {
        if (endEvent.pointerId !== event.pointerId) return;

        window.removeEventListener('pointermove', onMove);
        window.removeEventListener('pointerup', endDrag);
        window.removeEventListener('pointercancel', endDrag);

        if (frame !== null) {
            cancelAnimationFrame(frame);
            commitFrame();
        }

        draggingId.value = null;

        if (!moved) return;

        suppressClick = true;
        persistPosition(table, origin);
    };

    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', endDrag);
    window.addEventListener('pointercancel', endDrag);
}

/**
 * Saves a table's position, putting it back where it was if the request
 * fails. Without the rollback the floor plan silently disagrees with the
 * database until the next full page load — the table looks moved, and after
 * a refresh it is somewhere else.
 */
function persistPosition(table, previous) {
    router.patch(
        route('admin.tables.position', table.id),
        { pos_x: table.pos_x, pos_y: table.pos_y },
        {
            preserveScroll: true,
            preserveState: true,
            onError: () => {
                table.pos_x = previous.x;
                table.pos_y = previous.y;
            },
        },
    );
}

/**
 * Arrow-key nudging, for the last few pixels that are fiddly to hit with a
 * pointer — and the only way to move a table at all for anyone who can't use
 * one. Shift moves a whole grid cell at a time.
 */
function nudge(table, dx, dy, event) {
    const step = event.shiftKey ? GRID_PERCENT : 0.5;
    const previous = { x: table.pos_x, y: table.pos_y };
    const next = clampPosition(Number(table.pos_x) + dx * step, Number(table.pos_y) + dy * step);

    table.pos_x = next.x;
    table.pos_y = next.y;

    persistPosition(table, previous);
}

/**
 * A free spot for the next table, scanned left-to-right then down.
 *
 * New tables used to be created at a hardcoded 50/50, so adding several in a
 * row buried them all under each other in the middle of the canvas and every
 * one had to be dragged out of the pile before it could even be seen.
 */
function nextFreePosition() {
    const spacing = 10;
    const taken = props.tables.map((t) => ({ x: Number(t.pos_x), y: Number(t.pos_y) }));
    const isFree = (x, y) => !taken.some((t) => Math.hypot(t.x - x, t.y - y) < spacing * 0.9);

    for (let y = spacing; y <= 100 - spacing; y += spacing) {
        for (let x = spacing; x <= 100 - spacing; x += spacing) {
            if (isFree(x, y)) return { x, y };
        }
    }

    return { x: 50, y: 50 };
}

function openCreate() {
    const spot = nextFreePosition();
    createForm.pos_x = spot.x;
    createForm.pos_y = spot.y;
    showCreate.value = true;
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

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-gray-500">
                {{ $t('tables.drag_hint') }} · {{ $t('tables.click_hint') }} · {{ $t('tables.keyboard_hint') }}
            </p>
            <div class="flex flex-wrap items-center gap-2">
                <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-600 dark:text-gray-400" :title="$t('tables.snap_alt_hint')">
                    <Checkbox v-model:checked="snapToGrid" />
                    {{ $t('tables.snap_to_grid') }}
                </label>
                <a :href="route('admin.tables.qr-all')" target="_blank" rel="noopener">
                    <SecondaryButton>{{ $t('tables.print_all_qr') }}</SecondaryButton>
                </a>
                <PrimaryButton @click="openCreate">{{ $t('tables.add_table') }}</PrimaryButton>
            </div>
        </div>

        <div class="flex flex-col gap-6 lg:flex-row">
            <!-- The grid is drawn only while snapping is on, as a hint to
                 where a table will land — a static grid would just be noise
                 the rest of the time. It is decorative, hence aria-hidden and
                 pointer-events-none. -->
            <div
                ref="canvas"
                class="relative h-[32rem] min-w-0 flex-1 overflow-hidden rounded-lg border-2 border-dashed border-gray-300 bg-gray-50 dark:border-gray-700 dark:bg-gray-900/50"
            >
                <div
                    v-if="snapToGrid"
                    aria-hidden="true"
                    class="pointer-events-none absolute inset-0 opacity-60 dark:opacity-30"
                    :style="{
                        backgroundImage:
                            'linear-gradient(to right, rgb(148 163 184 / 0.25) 1px, transparent 1px),' +
                            'linear-gradient(to bottom, rgb(148 163 184 / 0.25) 1px, transparent 1px)',
                        backgroundSize: GRID_PERCENT * 2 + '% ' + GRID_PERCENT * 2 + '%',
                    }"
                ></div>

                <div
                    v-for="table in tables"
                    :key="table.id"
                    class="absolute h-16 w-16 -translate-x-1/2 -translate-y-1/2"
                    :class="draggingId === table.id ? 'z-20' : 'transition-[left,top] duration-150 ease-out'"
                    :style="{ left: table.pos_x + '%', top: table.pos_y + '%' }"
                >
                    <span
                        v-for="(pos, i) in chairs(table)"
                        :key="i"
                        class="pointer-events-none absolute h-2 w-3 -translate-x-1/2 -translate-y-1/2 rounded-[3px] border border-gray-300 bg-gray-100 dark:border-gray-600 dark:bg-gray-700"
                        :style="{ left: pos.left, top: pos.top }"
                    ></span>

                    <!-- touch-action:none is what makes this draggable on a
                         tablet at all: without it the browser claims the
                         gesture for panning the page and never delivers the
                         pointermove events, so tables simply could not be
                         moved by finger — on the devices the staff actually
                         use. -->
                    <button
                        type="button"
                        class="focus-brand absolute inset-0 flex touch-none flex-col items-center justify-center border-2 text-xs font-semibold shadow-sm transition-[transform,box-shadow] hover:z-10 hover:scale-105"
                        :class="[
                            statusClasses(table),
                            table.shape === 'round' ? 'rounded-full' : 'rounded-md',
                            draggingId === table.id ? 'scale-110 shadow-lg ring-2 ring-orange-400' : '',
                            !table.is_active ? 'opacity-50' : '',
                        ]"
                        :style="{ cursor: draggingId === table.id ? 'grabbing' : 'grab' }"
                        :title="table.active_order?.customer_name ?? null"
                        :aria-label="`${table.label} — ${table.seats}`"
                        @pointerdown="startDrag(table, $event)"
                        @click="selectTable(table)"
                        @keydown.up.prevent="nudge(table, 0, -1, $event)"
                        @keydown.down.prevent="nudge(table, 0, 1, $event)"
                        @keydown.left.prevent="nudge(table, -1, 0, $event)"
                        @keydown.right.prevent="nudge(table, 1, 0, $event)"
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

            <!-- Detail / edit panel: full width when stacked under the floor
                 plan on a narrow window (e.g. a PWA window resized small —
                 the old fixed w-80 sidebar had nowhere to go and could end
                 up squeezed out of view with no way to reach it), a fixed
                 sidebar once there's room to sit beside the canvas. -->
            <div v-if="selected" class="w-full shrink-0 rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900 lg:w-80">
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
