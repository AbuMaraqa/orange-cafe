<?php

namespace App\Observers;

use App\Models\Order;
use Illuminate\Support\Facades\Auth;

/**
 * Keeps the payment timeline in step with the bill's running total.
 *
 * `orders.amount_paid_cents` says how much a bill has taken; `order_payments`
 * says when each part of it arrived. The cash box reads the second, because a
 * running total cannot answer "on which day" — see the order_payments
 * migration for the failure that produced.
 *
 * ## Why an observer, when this codebase prefers explicit writes
 *
 * ExpenseRecorder writes an expense and its cash movement side by side, in
 * one place, on purpose. The same shape was the obvious thing to do here, in
 * StaffOrderController::markPaid(), and it is not enough — because markPaid()
 * is not the only thing that writes this column:
 *
 *  - `app:import-from-mysql` copies the `orders` table column by column from
 *    a cafe's previous installation. Orders would arrive fully paid with no
 *    timeline behind them, and every one of those days would report zero
 *    takings.
 *  - Seeders create paid orders directly, as does every test that needs one.
 *
 * An invariant that holds only when one particular controller is the writer
 * is not an invariant. This is the seam every writer passes through.
 *
 * ## The timestamp
 *
 * `paid_at` when the bill has one — which is the settle, and is also what
 * makes an imported order land on the day it was actually paid rather than
 * the day of the import. `now()` otherwise, which is the part-payment case:
 * the bill is still open, nothing has stamped it, and the money is crossing
 * the counter as this runs.
 */
class OrderObserver
{
    /**
     * A bill that arrives already paid — a seeder, the legacy importer, or a
     * test — brings its whole payment with it.
     *
     * Handled separately from updated() and not through `saved` for both,
     * because wasChanged() does not report on an insert: Eloquent syncs
     * changes in performUpdate() only, so a created row looks unchanged and
     * every imported bill would land with an empty timeline.
     */
    public function created(Order $order): void
    {
        $this->recordTheDifference($order, before: 0);
    }

    public function updated(Order $order): void
    {
        // `paid_at` matters as much as the amount. A bill settled without an
        // explicit figure collects its total (see collectedFor), and gating
        // only on the amount would let that settle through unrecorded.
        if (! $order->wasChanged('amount_paid_cents') && ! $order->wasChanged('paid_at')) {
            return;
        }

        // getOriginal() still holds the pre-save values here: syncOriginal()
        // runs in finishSave(), after this event.
        $this->recordTheDifference($order, before: $this->collectedFor(
            amountPaidCents: $order->getOriginal('amount_paid_cents'),
            paidAt: $order->getOriginal('paid_at'),
            totalCents: (int) $order->getOriginal('total_cents'),
        ));
    }

    /**
     * What changed hands in this write.
     *
     * A settle after instalments is stated as a total — "this bill has now
     * taken 100" — so the difference, not the figure written, is what reached
     * the drawer. recordPayment() ignores a zero.
     */
    private function recordTheDifference(Order $order, int $before): void
    {
        $after = $this->collectedFor(
            amountPaidCents: $order->amount_paid_cents,
            paidAt: $order->paid_at,
            totalCents: (int) $order->total_cents,
        );

        $order->recordPayment(
            deltaCents: $after - $before,
            userId: $order->paid_by ?? Auth::id(),
            at: $order->paid_at,
            // Read off the bill for the same reason the cashier and the
            // moment are: this is the seam every writer passes through, and
            // the till is only one of them. A seeder or the legacy importer
            // states the method on the order it creates and gets a timeline
            // that agrees with it; one that says nothing gets cash, which is
            // what every payment predating the card reader was.
            method: $order->payment_method,
            reference: $order->payment_reference,
        );
    }

    /**
     * How much a bill has taken, by exactly the rule Order::collectedCents()
     * uses — including its fallback.
     *
     * That fallback is not a detail to skip. A settled bill with no
     * `amount_paid_cents` is an ordinary row: the column came later, so every
     * order predating it looks like this, and so does anything arriving
     * through `app:import-from-mysql` from an installation old enough not to
     * have had it. Reading the column alone would file all of them as having
     * collected nothing.
     *
     * The null case is only a fallback for a bill that is *settled*. On one
     * still open, null means nobody has paid anything — the distinction
     * Order::paidSoFarCents() exists to make.
     */
    private function collectedFor(?int $amountPaidCents, mixed $paidAt, int $totalCents): int
    {
        if ($amountPaidCents !== null) {
            return $amountPaidCents;
        }

        return $paidAt !== null ? $totalCents : 0;
    }
}
