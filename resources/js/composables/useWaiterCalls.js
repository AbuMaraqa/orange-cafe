import { onMounted, onUnmounted, reactive, ref } from 'vue';
import { joinOrdersChannel } from '@/composables/useOrdersChannel';
import { playAlertChime, prepareAlertSound, soundBlocked } from '@/composables/useAlertSound';

/**
 * Keeps the waiter board's "customer wants a waiter" alerts in sync in
 * real time over the same `orders` private Reverb channel staff dashboards
 * already subscribe to (see useLiveOrders) — no polling, no extra channel.
 *
 * Calls are kept oldest-first throughout (the initial page load already
 * queries oldest-first) so the banner list doubles as a priority queue —
 * whoever has been waiting longest is always on top.
 */
export function useWaiterCalls(initialCalls) {
    const calls = reactive(initialCalls.map((c) => ({ ...c })));
    // The call currently shown as a full-screen popup. New calls that
    // arrive while one is already showing queue up in popupQueue instead
    // of silently replacing it — each gets its own turn as the waiter
    // dismisses/acknowledges the one in front, so a burst of calls (e.g.
    // several tables at once) can't hide any of them from the popup.
    const popupCall = ref(null);
    const popupQueue = [];

    function showNextPopup() {
        if (popupCall.value) return;

        const nextId = popupQueue.shift();
        if (nextId === undefined) return;

        const call = calls.find((c) => c.id === nextId);
        if (!call) {
            showNextPopup();
            return;
        }

        popupCall.value = call;
    }

    function remove(id) {
        const index = calls.findIndex((c) => c.id === id);
        if (index !== -1) calls.splice(index, 1);

        const queueIndex = popupQueue.indexOf(id);
        if (queueIndex !== -1) popupQueue.splice(queueIndex, 1);

        if (popupCall.value?.id === id) {
            popupCall.value = null;
            showNextPopup();
        }
    }

    function dismissPopup() {
        popupCall.value = null;
        showNextPopup();
    }

    // Reference counted — see useOrdersChannel. This composable runs
    // alongside the floor plan on the waiter screen, and leaving the channel
    // outright used to unsubscribe the floor plan's listeners with it, so
    // orders stopped appearing live on the very screen that needs them most.
    let leaveChannel = null;

    onMounted(async () => {
        // Ask for the browser's permission to make a sound before the
        // first call arrives, not on it.
        prepareAlertSound();

        leaveChannel = await joinOrdersChannel({
            '.waiter-call.requested': (event) => {
                calls.push(event.call);
                popupQueue.push(event.call.id);
                playAlertChime();
                showNextPopup();
            },
            '.waiter-call.acknowledged': (event) => remove(event.call.id),
        });
    });

    onUnmounted(() => leaveChannel?.());

    return { calls, popupCall, dismissPopup, soundBlocked };
}
