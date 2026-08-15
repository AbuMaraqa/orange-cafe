import { onMounted, onUnmounted, reactive, ref } from 'vue';

/**
 * Three beeps via the Web Audio API rather than an audio file — no asset
 * to ship/license, and it works the instant the page loads with no
 * network request. Browsers block audio before any user interaction with
 * the page, so this silently no-ops until the waiter has clicked/tapped
 * somewhere at least once (typical on a staff device that's already
 * being used).
 *
 * A busy floor is loud — this needs to cut through it, so it's louder
 * (peak gain roughly double the old two-beep chime) and a three-note
 * rising pattern on a 'triangle' wave instead of 'sine', which carries
 * more than a pure tone at the same volume without turning harsh.
 */
function playChime() {
    try {
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        const ctx = new AudioContextClass();
        const now = ctx.currentTime;

        [0, 0.16, 0.32].forEach((offset, i) => {
            const oscillator = ctx.createOscillator();
            const gain = ctx.createGain();
            oscillator.type = 'triangle';
            oscillator.frequency.value = [987.77, 1174.66, 1567.98][i]; // B5, D6, G6 — a brighter, more urgent rising triad
            gain.gain.setValueAtTime(0, now + offset);
            gain.gain.linearRampToValueAtTime(0.6, now + offset + 0.015);
            gain.gain.exponentialRampToValueAtTime(0.001, now + offset + 0.3);
            oscillator.connect(gain);
            gain.connect(ctx.destination);
            oscillator.start(now + offset);
            oscillator.stop(now + offset + 0.35);
        });

        setTimeout(() => ctx.close(), 900);
    } catch {
        // Audio isn't available (unsupported browser, blocked autoplay,
        // etc.) — the visible popup + banner still carries the alert.
    }
}

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

    let channel = null;

    onMounted(async () => {
        if (!window.Echo) {
            await import('../echo');
        }

        channel = window.Echo.private('orders');
        channel.listen('.waiter-call.requested', (event) => {
            calls.push(event.call);
            popupQueue.push(event.call.id);
            playChime();
            showNextPopup();
        });
        channel.listen('.waiter-call.acknowledged', (event) => remove(event.call.id));
    });

    onUnmounted(() => {
        window.Echo?.leave('orders');
    });

    return { calls, popupCall, dismissPopup };
}
