<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Folds a table's stray tickets back into the one bill it should have had.
 *
 * A table pays once, so a table carries one ticket. That is now enforced
 * where orders are created (OrderController::joinableTableOrder), but the
 * rule arrived after the tickets did: a café running the old behaviour
 * accumulated tables holding two or three live orders at once.
 *
 * That is not a cosmetic leftover. Every screen in the app reads a table's
 * *latest* order — RestaurantTable::activeOrder() and PresentsTables both
 * take the newest and stop — so the older ticket is invisible while still
 * being cooked, served and, crucially, never charged for. It is money
 * walking out of the door with no trace on any screen.
 *
 * The merge itself is the one already proven in
 * StaffOrderController::moveTable(): move the lines, carry the totals, close
 * the emptied ticket. This exists so that behaviour has one definition
 * rather than two, and so it can be applied to history as well as to the
 * next order that comes in.
 */
class TableTicketConsolidator
{
    /**
     * Tables holding more than one live ticket, newest ticket first.
     *
     * @return Collection<int, Collection<int, Order>> keyed by table id
     */
    public function conflicts(): Collection
    {
        return Order::query()
            ->whereNotNull('table_id')
            ->whereIn('status', config('orders.active_statuses'))
            ->whereNull('paid_at')
            ->with('items')
            ->latest()
            ->get()
            ->groupBy('table_id')
            ->filter(fn (Collection $orders) => $orders->count() > 1);
    }

    /**
     * Merges every stray ticket on every affected table into that table's
     * newest one, and returns what it did.
     *
     * The newest ticket is the survivor because it is the one already on
     * screen: the waiter is looking at it, the cashier is about to charge
     * it, and its id is in the URL they are on. Keeping it means the merge
     * changes what the bill *says* without changing what anyone is holding.
     *
     * @return array<int, array{table_id:int, kept:int, absorbed:array<int,int>, moved_cents:int}>
     */
    public function consolidate(bool $apply = true): array
    {
        $report = [];

        foreach ($this->conflicts() as $tableId => $orders) {
            /** @var Order $keep */
            $keep = $orders->first();
            $absorb = $orders->slice(1);

            $report[] = [
                'table_id' => (int) $tableId,
                'kept' => $keep->id,
                'absorbed' => $absorb->pluck('id')->all(),
                'moved_cents' => (int) $absorb->sum('total_cents'),
            ];

            if (! $apply) {
                continue;
            }

            DB::transaction(function () use ($keep, $absorb) {
                foreach ($absorb as $order) {
                    $this->fold($order, $keep);
                }
            });
        }

        return $report;
    }

    /**
     * The item ids moved by the last foldIntoTableBill() call.
     *
     * @var array<int, int>
     */
    private array $lastFoldedItemIds = [];

    /** @return array<int, int> map of orderItemId => quantity, for the printer. */
    public function lastFoldedItemIds(): array
    {
        return $this->lastFoldedItemIds;
    }

    /**
     * Folds a just-approved round into the bill its table is already running,
     * or returns null when the table has no other live ticket.
     *
     * Called when a member of staff confirms a customer's order. A table
     * that is already running a bill must not end up with two: every screen
     * shows a table's newest ticket and stops there, so the older one — the
     * one with the food on it — becomes invisible while still being served.
     *
     * The approved order is left in the books as cancelled rather than
     * deleted, carrying its own reference and timestamps, so "where did that
     * order go" has an answer six weeks later.
     */
    public function foldIntoTableBill(Order $order): ?Order
    {
        $this->lastFoldedItemIds = [];

        if ($order->table_id === null) {
            return null;
        }

        $bill = Order::query()
            ->where('table_id', $order->table_id)
            ->where('id', '!=', $order->id)
            ->whereIn('status', config('orders.active_statuses'))
            ->where('status', '!=', 'pending')
            ->whereNull('paid_at')
            ->oldest()
            ->first();

        if ($bill === null) {
            return null;
        }

        // Captured before the fold, because afterwards these rows belong to
        // the bill and there is nothing left to tell them apart from the
        // lines that printed an hour ago.
        $this->lastFoldedItemIds = $order->items()
            ->pluck('quantity', 'id')
            ->all();

        DB::transaction(function () use ($order, $bill) {
            $this->fold($order, $bill);

            // The pass had called this table done; there is food again.
            if ($bill->status === 'ready') {
                $bill->update(['status' => 'confirmed']);
            }
        });

        // A brand-new instance with its lines read back from the database.
        //
        // refresh() was not enough: the fold moves rows with a mass update,
        // so an `items` collection already sitting on this model — loaded by
        // whatever fetched the bill — still describes the ticket as it was
        // before the round arrived. The printer then filtered that stale
        // list for the new item ids, found none, and queued nothing at all:
        // the round was on the bill and the kitchen was never told.
        return $bill->fresh(['items']);
    }

    /**
     * One ticket into another.
     *
     * Deliberately not a "delete the loser" — it is cancelled, keeping its
     * row, its reference and its timestamps. A café that is asked six weeks
     * later where an order went needs to be able to answer, and a merge that
     * erases evidence of itself cannot be audited or undone.
     *
     * Money already handed over on the absorbed ticket travels with it.
     * Dropping it would leave a guest who paid half their bill being asked
     * for the whole of it again, which is the same failure as the one this
     * class exists to fix, pointed the other way.
     */
    private function fold(Order $order, Order $keep): void
    {
        $order->items()->update(['order_id' => $keep->id]);

        // Written as explicit sums rather than increment(), because
        // discount_cents and amount_paid_cents are nullable and SQL adds
        // nothing to NULL — it produces NULL. An increment on an untouched
        // bill would blank the very column being carried across.
        $paid = $keep->paidSoFarCents() + $order->paidSoFarCents();

        $keep->update([
            'total_cents' => $keep->total_cents + $order->total_cents,
            'discount_cents' => ($keep->discount_cents ?? 0) + ($order->discount_cents ?? 0),
            'amount_paid_cents' => $paid > 0 ? $paid : $keep->amount_paid_cents,
        ]);

        $order->update(['status' => 'cancelled']);
    }
}
