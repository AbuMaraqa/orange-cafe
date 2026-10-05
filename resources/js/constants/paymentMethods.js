/**
 * How a bill can be settled.
 *
 * Mirrors App\Models\OrderPayment's constants — the server validates against
 * its own list, so this one only has to agree with it. Kept here rather than
 * spelled out in each screen because the till, the cashier's order cards and
 * the bill sheet all offer the same choice, and a fourth place typing 'visa'
 * where the server expects 'card' fails as a validation error the cashier
 * cannot act on.
 */
export const PAYMENT_CASH = 'cash';
export const PAYMENT_CARD = 'card';

export const PAYMENT_METHODS = [PAYMENT_CASH, PAYMENT_CARD];

/**
 * The icon each method gets on the buttons. Cash and card are told apart at
 * a glance on a screen somebody is using with a customer waiting, which is
 * worth more here than it would be on an admin form.
 */
export const PAYMENT_METHOD_ICONS = {
    [PAYMENT_CASH]: 'cash',
    [PAYMENT_CARD]: 'creditCard',
};
