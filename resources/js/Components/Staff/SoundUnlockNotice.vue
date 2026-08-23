<script setup>
import NavIcon from '@/Components/NavIcon.vue';
import { playAlertChime, soundBlocked } from '@/composables/useAlertSound';

/**
 * Says out loud that this screen cannot make a sound yet.
 *
 * Browsers refuse to play audio until the page has been interacted with,
 * and they refuse silently. Without this the screen looks entirely normal
 * while every service call arrives mute — which on a waiter board mounted
 * on a wall, untouched since it was switched on, is every call of the day.
 *
 * Tapping it is itself the gesture the browser was waiting for, so the
 * button both explains the problem and fixes it. It plays the chime once on
 * the way through, which is the only honest confirmation that sound now
 * works.
 */
function enable() {
    playAlertChime();
}
</script>

<template>
    <button
        v-if="soundBlocked"
        type="button"
        class="mb-4 flex w-full items-center gap-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-start transition hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-950 dark:hover:bg-amber-900/60"
        @click="enable"
    >
        <NavIcon name="bell" class="h-5 w-5 shrink-0 text-amber-500" />
        <span class="flex-1 text-sm font-medium text-amber-900 dark:text-amber-100">
            {{ $t('waiter.sound_blocked') }}
        </span>
    </button>
</template>
