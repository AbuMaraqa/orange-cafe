// Tailwind's compiler needs full literal class strings in the source to
// generate them — a dynamically interpolated `bg-${color}-100` is invisible
// to it and would silently produce no styles, so every status color used
// anywhere in the app is spelled out here once, in one shared place.
const STATUS_BADGE_CLASSES = {
    amber: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400',
    blue: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400',
    indigo: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-400',
    teal: 'bg-teal-100 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400',
    green: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400',
    red: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400',
    gray: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
};

export function useOrderStatus() {
    function statusBadgeClasses(color) {
        return STATUS_BADGE_CLASSES[color] ?? STATUS_BADGE_CLASSES.gray;
    }

    return { statusBadgeClasses };
}
