import { usePage } from '@inertiajs/vue3';

/**
 * Money formatting, in one place.
 *
 * Everything crossing the wire is an integer number of cents — the whole
 * financial subsystem is built on that, because a float that has been
 * through JSON and back is not the number anyone typed. Nothing here
 * converts *into* cents: forms send what the user wrote and the server
 * multiplies (see NormalisesMoney), so there is exactly one place where a
 * rounding mistake could ever be made.
 */
export function useMoney() {
    const page = usePage();

    const symbol = () => page.props.branding?.currency_symbol ?? '';

    /** "12.50" — the bare number, for table cells and inputs. */
    function amount(cents) {
        return ((cents ?? 0) / 100).toFixed(2);
    }

    /** "₪12.50" — for anything a person reads as money. */
    function money(cents) {
        return `${symbol()}${amount(cents)}`;
    }

    /**
     * "+₪12.50" / "−₪12.50", with the sign spelled out.
     *
     * A minus sign that can be missed is the difference between a day that
     * balanced and one that did not, so the direction is never left to
     * colour alone.
     */
    function signed(cents) {
        const value = cents ?? 0;

        if (value === 0) return money(0);

        return `${value > 0 ? '+' : '−'}${money(Math.abs(value))}`;
    }

    /** Tailwind classes for a figure whose sign carries meaning. */
    function toneFor(cents) {
        if ((cents ?? 0) > 0) return 'text-green-600 dark:text-green-400';
        if ((cents ?? 0) < 0) return 'text-red-600 dark:text-red-400';

        return 'text-gray-500 dark:text-gray-400';
    }

    return { amount, money, signed, symbol, toneFor };
}

/** "20/08/2026" — the form the cafe writes dates in. */
export function formatDate(iso) {
    if (!iso) return '—';

    const [year, month, day] = iso.slice(0, 10).split('-');

    return `${day}/${month}/${year}`;
}

export function formatDateTime(iso) {
    if (!iso) return '—';

    const date = new Date(iso);

    return `${formatDate(iso)} ${date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })}`;
}
