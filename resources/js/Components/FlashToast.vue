<script setup>
import { computed, ref, watch } from 'vue';
import NavIcon from '@/Components/NavIcon.vue';
import { usePage } from '@inertiajs/vue3';

/**
 * What just happened, said out loud.
 *
 * The server has always flashed these messages and Inertia has always
 * carried them; nothing on the client ever rendered one. So every action on
 * every staff screen completed in total silence — including printing, where
 * silence is indistinguishable from a printer that is merely slow, and the
 * cashier's only remaining move is to press the button again.
 *
 * Three channels, because the difference between them is the whole point:
 * 'success' means it is on its way, 'warning' means nothing was sent and
 * that is fine, 'error' means nothing was sent and somebody has to do
 * something. A failure delivered through the success channel is worse than
 * no message at all.
 */
const page = usePage();

const visible = ref(false);
const message = ref('');
const level = ref('success');

let timer = null;

const flash = computed(() => page.props.flash ?? {});

// Success fades; a warning or an error stays until it is dismissed. Nobody
// should have to catch "the grill has no printer" before it disappears.
const AUTO_DISMISS_MS = 5000;

function show(nextLevel, text) {
    clearTimeout(timer);

    level.value = nextLevel;
    message.value = text;
    visible.value = true;

    if (nextLevel === 'success') {
        timer = setTimeout(() => (visible.value = false), AUTO_DISMISS_MS);
    }
}

watch(
    flash,
    (next) => {
        // Most severe first: a request that both queued something and hit a
        // station with no printer has a problem worth more than its success.
        if (next.error) show('error', next.error);
        else if (next.warning) show('warning', next.warning);
        else if (next.success) show('success', next.success);
    },
    { immediate: true, deep: true }
);

const styles = {
    success: 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100',
    warning: 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100',
    error: 'border-red-200 bg-red-50 text-red-900 dark:border-red-900 dark:bg-red-950 dark:text-red-100',
};

const icons = { success: 'check', warning: 'bell', error: 'bell' };
</script>

<template>
    <!-- Fixed and high: this competes with modals (the till's bill sheet is
         one), and a message hidden behind the sheet you pressed print from
         is a message that does not exist. -->
    <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="-translate-y-2 opacity-0"
        leave-active-class="transition duration-150 ease-in"
        leave-to-class="-translate-y-2 opacity-0"
    >
        <div
            v-if="visible"
            class="fixed inset-x-0 top-4 z-[100] mx-auto flex w-[min(28rem,calc(100%-2rem))] items-start gap-3 rounded-lg border px-4 py-3 shadow-lg"
            :class="styles[level]"
            role="status"
            aria-live="polite"
        >
            <NavIcon :name="icons[level]" class="mt-0.5 h-5 w-5 shrink-0" />
            <p class="flex-1 text-sm font-medium leading-snug">{{ message }}</p>
            <button
                type="button"
                class="shrink-0 opacity-60 transition hover:opacity-100"
                :aria-label="$t('common.close')"
                @click="visible = false"
            >
                <NavIcon name="close" class="h-4 w-4" />
            </button>
        </div>
    </Transition>
</template>
