<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import NavIcon from '@/Components/NavIcon.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

/**
 * "Table 9 is asking for you", over whatever the screen was showing.
 *
 * Lifted out of the waiter screen so the till can show the same thing. It
 * used to live inline in Waiter/Index.vue, and the cashier — who is often
 * the only person standing still, and whose own job the "bill" request
 * literally is — got a quiet banner down the page instead. A request that
 * arrives where nobody is looking has not arrived.
 *
 * Copying the markup across would have been two of these to keep in step,
 * and they would not have stayed in step: the point of a call popup is that
 * staff recognise it instantly, which stops being true the moment the till's
 * version drifts from the floor's.
 */
const props = defineProps({
    /** The call to show, or null for none. */
    call: { type: Object, default: null },

    /** How many other calls are still waiting behind this one. */
    remainingCount: { type: Number, default: 0 },

    /**
     * Whether this screen can resolve the call, rather than just look at it.
     *
     * Acknowledging removes it from every screen, so it is the difference
     * between "I have this" and "I have seen this" — and only somebody who
     * is actually going to walk over should be able to say the first.
     */
    canAcknowledge: { type: Boolean, default: false },
});

const emit = defineEmits(['dismiss', 'acknowledge']);

const { t } = useI18n();

const label = computed(() => {
    const call = props.call;

    if (!call) return '';

    if (call.service_name) {
        return call.table_label
            ? t('waiter.call_from_table_service', { table: call.table_label, service: call.service_name })
            : t('waiter.call_generic_service', { service: call.service_name });
    }

    return call.table_label ? t('waiter.call_from_table', { table: call.table_label }) : t('waiter.call_generic');
});
</script>

<template>
    <div
        v-if="call"
        class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4"
        role="alertdialog"
        aria-live="assertive"
        :aria-label="label"
        @click.self="emit('dismiss')"
    >
        <div class="w-full max-w-sm rounded-2xl bg-white p-6 text-center shadow-2xl dark:bg-gray-900">
            <p class="flex items-center justify-center gap-1.5 text-sm font-semibold uppercase tracking-wide text-amber-600 dark:text-amber-400">
                <NavIcon name="bell" class="h-4 w-4" /> {{ t('waiter.call_popup_title') }}
            </p>

            <!-- The service's own photo/color is the biggest visual here —
                 with several call types in flight (charcoal, bill, extra
                 chair…) staff need to tell them apart at a glance, not just
                 see "a call happened". The table number rides along as a
                 badge on top of it, since it's still the one fact that
                 decides where to walk. -->
            <div class="relative mx-auto mb-6 mt-4 h-28 w-28">
                <div
                    class="flex h-full w-full items-center justify-center overflow-hidden rounded-2xl text-5xl font-bold text-white shadow-lg"
                    :style="{ backgroundColor: call.service_color || '#f59e0b' }"
                >
                    <img v-if="call.service_image_url" :src="call.service_image_url" class="h-full w-full object-cover" :alt="call.service_name ?? ''" />
                    <template v-else-if="call.service_icon">{{ call.service_icon }}</template>
                    <NavIcon v-else name="bell" class="h-12 w-12" />
                </div>
                <span class="absolute left-1/2 -bottom-5 -translate-x-1/2 whitespace-nowrap rounded-full bg-white px-4 py-1.5 text-2xl font-extrabold text-gray-900 shadow-md dark:bg-gray-800 dark:text-gray-100">
                    {{ call.table_label ?? '?' }}
                </span>
            </div>

            <p class="mt-4 text-base font-medium text-gray-900 dark:text-gray-100">{{ label }}</p>

            <p v-if="remainingCount > 0" class="mt-1 text-xs font-medium text-amber-600 dark:text-amber-400">
                {{ t('waiter.more_calls_waiting', { count: remainingCount }) }}
            </p>

            <div class="mt-5 flex gap-2">
                <SecondaryButton class="flex-1 justify-center" type="button" @click="emit('dismiss')">
                    {{ t('waiter.dismiss') }}
                </SecondaryButton>
                <PrimaryButton v-if="canAcknowledge" class="flex-1 justify-center" type="button" @click="emit('acknowledge', call)">
                    {{ t('waiter.acknowledge') }}
                </PrimaryButton>
            </div>
        </div>
    </div>
</template>
