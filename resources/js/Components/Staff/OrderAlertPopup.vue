<script setup>
import { onMounted, onUnmounted, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import NavIcon from '@/Components/NavIcon.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { useMoney } from '@/composables/useMoney';

/**
 * "Table 6 just ordered", in the middle of the screen.
 *
 * Deliberately the same shape as the waiter-call popup (Waiter/Index.vue):
 * a table number big enough to read from across a room, on a card that
 * covers whatever the screen was showing. The two are the only things on
 * these screens that interrupt, and they should look like siblings — a
 * member of staff learns one visual language, not two.
 *
 * Dismissing is the only action. Unlike a call there is nothing to
 * acknowledge: the order is already on the floor plan, in the kitchen list
 * and on the till. This card exists to make sure a human *knows*, and once
 * they do it has done its job.
 *
 * @see useOrderAlerts — owns the queue, the de-duplication and the chime.
 */
const props = defineProps({
    /** @type {{id:number, reference:?string, tableLabel:?string, itemsCount:?number, totalCents:?number, isAddition:boolean, at:Date}|null} */
    alert: { type: Object, default: null },
    /** How many more are queued behind this one. */
    pendingCount: { type: Number, default: 0 },
});

const emit = defineEmits(['dismiss']);

const { t } = useI18n();
const { money } = useMoney();

// An addition is a different piece of news from a first order — the waiter
// has already been to this table — so it is coloured and worded apart
// rather than repeating "new order" at them.
const accent = computed(() =>
    props.alert?.isAddition
        ? {
            text: 'text-amber-600 dark:text-amber-400',
            tile: 'bg-amber-500',
            icon: 'plus',
            title: t('order_alert.addition_title'),
        }
        : {
            text: 'text-emerald-600 dark:text-emerald-400',
            tile: 'bg-emerald-500',
            icon: 'receipt',
            title: t('order_alert.new_title'),
        }
);

const placedAt = computed(() => {
    const at = props.alert?.at;

    if (!at) return '';

    return new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' }).format(at);
});

// Escape closes it, like every other dismissible surface in the app. Bound
// on the window rather than the card because the card is never focused —
// nobody tabs to an alert that appeared while their hands were full.
function onKeydown(event) {
    if (event.key === 'Escape' && props.alert) {
        emit('dismiss');
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onUnmounted(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="scale-95 opacity-0"
        leave-active-class="transition duration-150 ease-in"
        leave-to-class="scale-95 opacity-0"
    >
        <div
            v-if="alert"
            class="fixed inset-0 z-[60] flex items-center justify-center bg-gray-900/50 p-4"
            role="alertdialog"
            aria-live="assertive"
            :aria-label="accent.title"
            @click.self="emit('dismiss')"
        >
            <div class="w-full max-w-sm rounded-2xl bg-white p-6 text-center shadow-2xl dark:bg-gray-900">
                <p class="flex items-center justify-center gap-1.5 text-sm font-semibold uppercase tracking-wide" :class="accent.text">
                    <NavIcon :name="accent.icon" class="h-4 w-4" />
                    {{ accent.title }}
                </p>

                <!-- The table number is the one fact that decides where to
                     walk, so it gets the size. Everything else on this card
                     is context for it. -->
                <div
                    class="mx-auto mb-4 mt-4 flex h-28 w-28 items-center justify-center rounded-2xl text-5xl font-bold text-white shadow-lg"
                    :class="accent.tile"
                >
                    <template v-if="alert.tableLabel">{{ alert.tableLabel }}</template>
                    <NavIcon v-else name="receipt" class="h-12 w-12" />
                </div>

                <p class="mt-4 text-base font-medium text-gray-900 dark:text-gray-100">
                    {{ alert.tableLabel ? t('order_alert.from_table', { table: alert.tableLabel }) : t('order_alert.no_table') }}
                </p>

                <!-- Item count and total together answer "is this a coffee
                     or a full table?" without opening anything. -->
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    <span v-if="alert.itemsCount != null">{{ t('order_alert.items', { count: alert.itemsCount }) }}</span>
                    <span v-if="alert.itemsCount != null && alert.totalCents != null" aria-hidden="true"> · </span>
                    <span v-if="alert.totalCents != null">{{ money(alert.totalCents) }}</span>
                </p>

                <p class="mt-1 flex items-center justify-center gap-1.5 text-xs text-gray-400 dark:text-gray-500">
                    <NavIcon name="clock" class="h-3.5 w-3.5" />
                    <span>{{ placedAt }}</span>
                    <span v-if="alert.reference" aria-hidden="true">·</span>
                    <span v-if="alert.reference" class="font-mono">{{ alert.reference }}</span>
                </p>

                <p v-if="pendingCount > 0" class="mt-2 text-xs font-medium" :class="accent.text">
                    {{ t('order_alert.more_waiting', { count: pendingCount }) }}
                </p>

                <PrimaryButton class="mt-5 w-full justify-center" type="button" @click="emit('dismiss')">
                    {{ t('order_alert.dismiss') }}
                </PrimaryButton>
            </div>
        </div>
    </Transition>
</template>
