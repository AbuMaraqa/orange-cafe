<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import NavIcon from '@/Components/NavIcon.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useI18n } from 'vue-i18n';

/**
 * The list of tables currently asking for someone.
 *
 * Lifted out of the waiter board so the till and the dashboard can show it
 * too. A service request is not only the waiter's problem: the cashier is
 * often the person standing closest to the floor, and on a quiet shift the
 * waiter screen may not be open on any device at all — in which case the
 * request made a sound nobody heard and drew a banner nobody was looking
 * at.
 *
 * Oldest first, because whoever has waited longest is the most urgent, and
 * the list doubles as the queue to work through.
 */
const props = defineProps({
    calls: { type: Array, required: true },
    /**
     * Whether to offer the "acknowledge" button. The waiter answers a call;
     * the till and the dashboard are being told, not asked, and a button
     * that clears somebody else's queue is one a cashier will press by
     * accident while reaching for the pay button.
     */
    canAcknowledge: { type: Boolean, default: false },
});

defineEmits(['acknowledge']);

const { t } = useI18n();

// Ticks every 15s purely to force the wait time to recompute — a call that
// has been sitting for a while should visibly age without a reload, so
// anyone glancing at the screen can tell which table has waited longest.
const nowTick = ref(Date.now());
let tickInterval = null;

onMounted(() => {
    tickInterval = setInterval(() => (nowTick.value = Date.now()), 15000);
});

onUnmounted(() => clearInterval(tickInterval));

function waitMinutes(call) {
    void nowTick.value;

    return Math.max(0, Math.round((Date.now() - new Date(call.created_at).getTime()) / 60000));
}

function label(call) {
    if (call.service_name) {
        return call.table_label
            ? t('waiter.call_from_table_service', { table: call.table_label, service: call.service_name })
            : t('waiter.call_generic_service', { service: call.service_name });
    }

    return call.table_label ? t('waiter.call_from_table', { table: call.table_label }) : t('waiter.call_generic');
}

// Flagged once it has been over two minutes, so the eye goes straight to
// the most urgent table in a busy moment.
const urgentCallId = computed(() => {
    if (props.calls.length === 0) return null;

    return waitMinutes(props.calls[0]) >= 2 ? props.calls[0].id : null;
});
</script>

<template>
    <div v-if="calls.length > 0" class="mb-4 space-y-2">
        <div
            v-for="call in calls"
            :key="call.id"
            class="flex items-center justify-between gap-3 rounded-lg border px-4 py-3 transition-colors"
            :class="call.id === urgentCallId
                ? 'border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-900/30'
                : 'border-amber-300 bg-amber-50 dark:border-amber-800 dark:bg-amber-900/30'"
        >
            <div class="flex min-w-0 items-center gap-2">
                <span
                    v-if="call.service_icon || call.service_image_url"
                    class="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full text-lg leading-none text-white"
                    :style="{ backgroundColor: call.service_color }"
                >
                    <img v-if="call.service_image_url" :src="call.service_image_url" class="h-full w-full object-cover" />
                    <template v-else>{{ call.service_icon }}</template>
                </span>
                <NavIcon
                    v-else
                    name="bell"
                    class="h-5 w-5 shrink-0"
                    :class="call.id === urgentCallId ? 'animate-pulse text-red-500' : 'text-amber-500'"
                />
                <p
                    class="min-w-0 text-sm font-medium"
                    :class="call.id === urgentCallId ? 'text-red-800 dark:text-red-300' : 'text-amber-800 dark:text-amber-300'"
                >
                    {{ label(call) }}
                    <span class="font-normal opacity-75">
                        · {{ waitMinutes(call) < 1 ? t('staff.just_now') : t('staff.minutes_ago', { minutes: waitMinutes(call) }) }}
                    </span>
                </p>
            </div>

            <SecondaryButton v-if="canAcknowledge" type="button" @click="$emit('acknowledge', call)">
                {{ t('waiter.acknowledge') }}
            </SecondaryButton>
        </div>
    </div>
</template>
