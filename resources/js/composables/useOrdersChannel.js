/**
 * The one subscription to the `orders` channel, shared by everything that
 * listens on it.
 *
 * Three composables listen here — the floor plan, the live order list and
 * the waiter-call alerts — and a staff screen runs two of them at once.
 * Each used to subscribe with `Echo.private('orders')` and unsubscribe with
 * `Echo.leave('orders')` of its own.
 *
 * `Echo.leave()` does not remove one listener. It tears down the whole
 * channel, taking every other component's listeners with it — so on a page
 * with two of these, the first one to unmount silently deafened the second.
 * Worse, Inertia mounts the incoming page before unmounting the outgoing
 * one: the new screen subscribed, then the old screen's teardown ran and
 * destroyed the subscription it had just made. The result was a waiter
 * screen that was connected, showed no error, and never received another
 * order until someone reloaded the page — while the cashier screen, which
 * ran only one of these composables, kept working and made the whole thing
 * look like a problem with the waiter screen specifically.
 *
 * So the channel is reference counted here: joining registers listeners on
 * the shared channel, and only the last one to leave actually leaves.
 */

let channel = null;
let subscribers = 0;

/**
 * @param {Object<string, Function>} listeners  event name → handler
 * @returns {Promise<Function>} call it to remove exactly these listeners
 */
export async function joinOrdersChannel(listeners) {
    if (!window.Echo) {
        await import('../echo');
    }

    // Echo returns the same channel for repeated calls, so this is only
    // ever one real subscription however many components ask for it.
    channel = window.Echo.private('orders');
    subscribers += 1;

    const entries = Object.entries(listeners);

    entries.forEach(([event, handler]) => channel.listen(event, handler));

    let left = false;

    return () => {
        // Guard against a double teardown decrementing the count twice and
        // leaving a live screen unsubscribed.
        if (left) return;

        left = true;
        subscribers -= 1;

        entries.forEach(([event, handler]) => channel?.stopListening(event, handler));

        if (subscribers <= 0) {
            subscribers = 0;
            window.Echo?.leave('orders');
            channel = null;
        }
    };
}

/**
 * Runs `onReconnect` every time the socket comes back, but never on the
 * first connect — the page has just been rendered by the server then, so it
 * is already current.
 *
 * Anything that happened while the socket was away is simply lost: it
 * reconnects and carries on from the next event, with no idea what it
 * missed. Whatever that was stays wrong on screen forever, because the
 * correction *was* the missed event. Re-reading from the server on every
 * reconnect is what makes that self-healing.
 *
 * @returns {Function} unbind
 */
export function onSocketReconnect(onReconnect) {
    const connection = window.Echo?.connector?.pusher?.connection;

    if (!connection) {
        return () => {};
    }

    let seenFirstConnect = connection.state === 'connected';

    const handler = () => {
        if (seenFirstConnect) {
            onReconnect();
        }

        seenFirstConnect = true;
    };

    connection.bind('connected', handler);

    return () => connection.unbind('connected', handler);
}
