export const STATUS_FLOW = ['pending', 'confirmed', 'preparing', 'ready', 'completed'];

export function nextStatus(current) {
    const index = STATUS_FLOW.indexOf(current);
    return index === -1 || index === STATUS_FLOW.length - 1 ? null : STATUS_FLOW[index + 1];
}
