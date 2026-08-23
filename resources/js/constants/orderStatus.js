export const STATUS_FLOW = ['pending', 'confirmed', 'ready', 'completed'];

export function nextStatus(current) {
    const index = STATUS_FLOW.indexOf(current);
    return index === -1 || index === STATUS_FLOW.length - 1 ? null : STATUS_FLOW[index + 1];
}

// The statuses a table is still live in — anything else is a record: a
// completed bill or a cancelled one. Mirrors config/orders.php
// active_statuses, which is what the server enforces.
export const ACTIVE_STATUSES = ['pending', 'confirmed', 'ready'];
