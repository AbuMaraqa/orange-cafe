import { onMounted, onUnmounted, reactive } from 'vue';

/**
 * Keeps the waiter board's "customer wants a waiter" alerts in sync in
 * real time over the same `orders` private Reverb channel staff dashboards
 * already subscribe to (see useLiveOrders) — no polling, no extra channel.
 */
export function useWaiterCalls(initialCalls) {
    const calls = reactive(initialCalls.map((c) => ({ ...c })));

    function remove(id) {
        const index = calls.findIndex((c) => c.id === id);
        if (index !== -1) calls.splice(index, 1);
    }

    let channel = null;

    onMounted(async () => {
        if (!window.Echo) {
            await import('../echo');
        }

        channel = window.Echo.private('orders');
        channel.listen('.waiter-call.requested', (event) => calls.unshift(event.call));
        channel.listen('.waiter-call.acknowledged', (event) => remove(event.call.id));
    });

    onUnmounted(() => {
        window.Echo?.leave('orders');
    });

    return { calls };
}
