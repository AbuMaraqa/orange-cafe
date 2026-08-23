<?php

namespace App\Http\Controllers;

use App\Events\OrderStatusUpdated;
use App\Http\Controllers\Concerns\BroadcastsSafely;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PrintJob;
use App\Models\RestaurantTable;
use App\Models\ServiceCharge;
use App\Services\CashierReceiptPrinterService;
use App\Services\KitchenPrinterService;
use App\Services\LanguageRegistry;
use App\Services\PdfService;
use App\Services\Printing\PrintDispatchResult;
use App\Services\TableTicketConsolidator;
use App\Settings\RestaurantSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\Response;

class StaffOrderController extends Controller
{
    use BroadcastsSafely;

    public function updateStatus(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(config('orders.statuses')))],
        ]);

        $order->update(['status' => $data['status']]);

        $this->safeBroadcast(new OrderStatusUpdated($order), toOthers: true);

        return back()->with('success', __('orders.status_updated'));
    }

    public function markPaid(Request $request, Order $order, CashierReceiptPrinterService $cashierPrinter): RedirectResponse
    {
        $data = $request->validate([
            // Optional — a cashier can round down or discount at the till
            // (bill is 202, takes 200), so this may legitimately be less
            // (or more, e.g. leaving a tip) than the order total.
            'amount_paid_cents' => ['nullable', 'integer', 'min:0'],
            // A deliberate part-payment: take this much now and leave the
            // bill open for the rest.
            'partial' => ['sometimes', 'boolean'],
        ]);

        $handedOver = $data['amount_paid_cents'] ?? $order->total_cents;
        $partial = (bool) ($data['partial'] ?? false);

        // Payments accumulate. A table paying in instalments hands over
        // money more than once, and each amount is a fact about this bill
        // rather than a replacement for the last one — overwriting would
        // erase every payment but the final one from the day's takings.
        $paidSoFar = $partial
            ? $order->paidSoFarCents() + $handedOver
            : $handedOver;

        // A bill closes when it is covered, not when somebody says so. A
        // part-payment that happens to cover the rest settles the table on
        // the spot; one that does not leaves it open and owing, which is
        // the whole point of taking it.
        $settled = ! $partial || $paidSoFar >= $order->total_cents;

        $order->update([
            'paid_at' => $settled ? now() : null,
            'paid_by' => $settled ? $request->user()->id : null,
            'amount_paid_cents' => $paidSoFar,
        ]);

        // Only for money actually being put in it. A part-payment is still
        // cash crossing the counter, so the drawer opens either way.
        $cashierPrinter->queueDrawerOpen();

        $this->safeBroadcast(new OrderStatusUpdated($order->fresh()), toOthers: true);

        if ($settled) {
            return back()->with('success', __('orders.marked_paid'));
        }

        return back()->with('success', __('orders.partially_paid', [
            'paid' => number_format($paidSoFar / 100, 2),
            'remaining' => number_format($order->fresh()->outstandingCents() / 100, 2),
        ]));
    }

    /**
     * A customer-placed order sits "pending" and unprinted until a waiter
     * reviews and confirms it — this is that confirmation. Distinct from
     * updateStatus() (which the waiter no longer has access to) because
     * accepting a brand-new order is a different action from advancing an
     * already-accepted one through the kitchen workflow.
     */
    public function confirm(Order $order, KitchenPrinterService $printer, TableTicketConsolidator $consolidator): RedirectResponse
    {
        abort_unless($order->status === 'pending', 403);

        // A table already running a bill gets this round folded into it.
        //
        // This is the second half of the rule in
        // OrderController::joinableTableOrder. A customer ordering again
        // after the waiter confirmed their first round cannot join that
        // ticket unreviewed, so their items arrive as a pending order of
        // their own and alert the floor exactly as the first one did. Left
        // there it would be a second live ticket on one table — the split
        // bill that let food be served and never charged for. Merging at the
        // moment a member of staff accepts it keeps both halves: nothing
        // reaches the kitchen unreviewed, and the table still pays once.
        //
        // Only the newly confirmed lines go to the pass; the running ticket
        // printed when it was confirmed and must not print again.
        $mergedInto = $consolidator->foldIntoTableBill($order);

        if ($mergedInto) {
            $this->safeBroadcast(new OrderStatusUpdated($order->fresh()), toOthers: true);
            $this->safeBroadcast(new OrderStatusUpdated($mergedInto->fresh()), toOthers: true);

            $printer->queuePrintJobs($mergedInto, onlyItemIds: $consolidator->lastFoldedItemIds());

            return back()->with('success', __('orders.confirmed'));
        }

        $order->update(['status' => 'confirmed']);

        $this->safeBroadcast(new OrderStatusUpdated($order), toOthers: true);

        // Kitchens only. Confirming an order is a message to the people who
        // have to cook it, and nothing else — the bill is a separate moment
        // that belongs to the customer asking for it, not to the waiter
        // accepting the order.
        //
        // The till used to print here too, which meant a receipt appeared at
        // the counter for a table that had just sat down and would not pay
        // for another hour: paper nobody had asked for, for an amount that
        // was about to change as the table ordered more.
        //
        // The bill now prints when somebody presses "طباعة للكاشير" (see
        // printForCashier). Payment opens the drawer but does not print
        // either — see markPaid.
        $printer->queuePrintJobs($order);

        return back()->with('success', __('orders.confirmed'));
    }

    /**
     * Refuses to change a line on an order that is over.
     *
     * The cutoff used to be confirmation: once the ticket printed, the lines
     * were closed to everyone. The owner asked for it moved, because the
     * corrections they actually deal with arrive after the kitchen has the
     * order — a price the menu had wrong, a plate that came out late and
     * came off the bill — and a discount cannot say which line it was for.
     *
     * What that costs is worth writing down, because nothing in the app will
     * say it out loud: after confirmation the paper in the kitchen no longer
     * has to agree with the bill. A quantity lowered from three to one
     * leaves the third plate cooked, served and uncharged. The edit is
     * logged (see updateItem) and the screen warns whoever is making it, and
     * that is the whole of the protection.
     *
     * Still refused for a floor role — the routes are role:admin-only — once
     * the order is paid (removeItem/updateItem check that first, the same
     * cutoff moveTable uses), and here, once the order has left the active
     * flow: a completed or cancelled order is a record, not a bill.
     */
    private function abortIfTheOrderIsClosed(Order $order): void
    {
        abort_unless(
            in_array($order->status, config('orders.active_statuses'), true),
            403,
            __('orders.cannot_edit_closed'),
        );
    }

    public function removeItem(Order $order, OrderItem $item): RedirectResponse
    {
        abort_if($item->order_id !== $order->id, 404);
        abort_if($order->isPaid(), 403);
        $this->abortIfTheOrderIsClosed($order);

        $item->delete();
        $order->refresh()->recalculateTotal();

        $this->safeBroadcast(new OrderStatusUpdated($order->fresh()), toOthers: true);

        return back()->with('success', __('orders.item_removed'));
    }

    /**
     * Edits one line on a running order: its price, its quantity, or both.
     *
     * A discount agreed at the table, a price the menu had wrong, a plate
     * that came out late and was taken off the bill, a fourth coffee added
     * to a line that already says three — all of it happens, and without
     * this the only way to reflect any of it was to delete the line, which
     * loses what the customer actually ate and what the kitchen actually
     * cooked.
     *
     * Refused once the order is paid, the same cutoff removeItem() and
     * moveTable() use: after money has changed hands the recorded total has
     * to keep matching what was collected.
     *
     * The order total is recomputed from its lines rather than adjusted by
     * the difference. Money is the one place where a running balance that
     * drifts by a cent per edit is not acceptable, and summing is not slower
     * in any way that matters here.
     */
    public function updateItem(Request $request, Order $order, OrderItem $item): RedirectResponse
    {
        abort_if($item->order_id !== $order->id, 404);
        abort_if($order->isPaid(), 403);
        $this->abortIfTheOrderIsClosed($order);

        $validated = $request->validate([
            // In cents, and zero is allowed on purpose: taking an item down
            // to nothing is how a comped dish is recorded while keeping it
            // on the bill for the kitchen and the day's figures.
            'unit_price_cents' => ['required_without:quantity', 'integer', 'min:0', 'max:100000000'],
            // Zero is not a quantity — a line nobody is having is a removal,
            // which is removeItem()'s job and leaves a different trail.
            'quantity' => ['required_without:unit_price_cents', 'integer', 'min:1', 'max:999'],
        ]);

        $previous = ['price' => $item->unit_price_cents, 'quantity' => $item->quantity];

        $item->unit_price_cents = $validated['unit_price_cents'] ?? $item->unit_price_cents;
        $item->quantity = $validated['quantity'] ?? $item->quantity;
        $item->subtotal_cents = $item->unit_price_cents * $item->quantity;
        $item->save();

        $order->recalculateTotal();

        // Money changing by hand is worth a trail: who, which line, from
        // what to what.
        Log::info('Order line edited by an administrator.', [
            'order' => $order->reference,
            'item' => $item->id,
            'product' => $item->product_name,
            'from' => $previous,
            'to' => ['price' => $item->unit_price_cents, 'quantity' => $item->quantity],
            'user' => $request->user()?->id,
        ]);

        $this->safeBroadcast(new OrderStatusUpdated($order->fresh()), toOthers: true);

        return back()->with('success', __('orders.item_price_updated'));
    }

    /**
     * Takes an agreed discount off the whole bill.
     *
     * Repricing lines could already reduce a bill, but it lies about what
     * happened: the record then says the coffee costs 8 when the menu says
     * 12, and at the end of the day nobody can tell a discount from a price
     * change. A discount is its own number, so the lines stay honest and
     * the reduction stays visible on the receipt and in the figures.
     *
     * The cashier owns this one — it is agreed at the till, in front of the
     * customer, and waiting for an administrator to walk over is exactly
     * how it ends up being done by editing prices instead.
     *
     * Refused once the order is paid, the same cutoff the rest of the bill
     * edits use: after money has changed hands the recorded total has to
     * keep matching what was collected.
     */
    public function applyDiscount(Request $request, Order $order): RedirectResponse
    {
        abort_if($order->isPaid(), 403);

        $subtotal = $order->itemsSubtotalCents();

        $validated = $request->validate([
            // Capped at the bill itself: a discount bigger than the total
            // is a refund, which is a different transaction with different
            // paperwork. Zero clears a discount that was entered by
            // mistake.
            'discount_cents' => ['required', 'integer', 'min:0', 'max:'.$subtotal],
            'discount_reason' => ['nullable', 'string', 'max:120'],
        ]);

        $previous = $order->discount_cents ?? 0;

        $order->forceFill([
            'discount_cents' => $validated['discount_cents'],
            'discount_reason' => $validated['discount_reason'] ?? null,
        ])->save();

        $order->recalculateTotal();

        Log::info('Order discount changed.', [
            'order' => $order->reference,
            'from_cents' => $previous,
            'to_cents' => $order->discount_cents,
            'reason' => $order->discount_reason,
            'user' => $request->user()?->id,
        ]);

        $this->safeBroadcast(new OrderStatusUpdated($order->fresh()), toOthers: true);

        return back()->with('success', __('orders.discount_updated'));
    }

    /**
     * Puts a service charge on the bill — a table held past two hours, a
     * cover charge, corkage.
     *
     * A line, not a discount in reverse: the customer is being asked to pay
     * for something, so it belongs where they can read it, with its own
     * name and its own price, and it has to survive into the day's takings
     * as trade. A negative number buried in the total tells nobody what
     * they paid for.
     *
     * The name and the amount are copied onto the line, like every other
     * line here (see the order_items migration): a price list edited in
     * March must not restate a bill settled in February. The line then
     * behaves like any other — repriced, removed, discounted — except at
     * the pass, where it never prints. See the KitchenPrinterService
     * filter, and ServiceChargeNeverPrintsTest.
     *
     * Allowed while the bill is unpaid rather than only while pending: the
     * charge exists precisely because the table has been sitting a long
     * time, which is to say long after the kitchen took the order.
     */
    public function addServiceCharge(Request $request, Order $order): RedirectResponse
    {
        abort_if($order->isPaid(), 403);
        $this->abortIfTheOrderIsClosed($order);

        $validated = $request->validate([
            'service_charge_id' => ['required', 'integer', 'exists:service_charges,id'],
            // The till may take less than the price list says — an apology
            // for a slow night, a regular. Absent means "what it costs".
            'amount_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ]);

        $charge = ServiceCharge::query()->active()->findOrFail($validated['service_charge_id']);
        $amount = $validated['amount_cents'] ?? $charge->amount_cents;

        $item = $order->items()->create([
            'service_charge_id' => $charge->id,
            'product_name' => $charge->name,
            'prints_to_kitchen' => false,
            'station' => null,
            'unit_price_cents' => $amount,
            'quantity' => 1,
            'subtotal_cents' => $amount,
        ]);

        $order->recalculateTotal();

        Log::info('Service charge added to a bill.', [
            'order' => $order->reference,
            'charge' => $charge->name,
            'item' => $item->id,
            'amount_cents' => $amount,
            'user' => $request->user()?->id,
        ]);

        $this->safeBroadcast(new OrderStatusUpdated($order->fresh()), toOthers: true);

        return back()->with('success', __('orders.service_charge_added'));
    }

    /**
     * Closes a table without charging for it.
     *
     * The everyday case is a table that was opened and then did not happen:
     * a customer who changed their mind, a walk-in who left, an order rung
     * in on the wrong table. Until now the only way to clear one was to
     * mark it paid, which put money in the day's takings that nobody
     * handed over — the cash box then disagreed with the till by exactly
     * the value of every abandoned table.
     *
     * A paid order is refused. Once money has changed hands the correction
     * is a refund, which is a different decision with a different record,
     * and quietly flipping a settled bill to "cancelled" would erase the
     * takings it belongs to.
     */
    public function cancel(Request $request, Order $order): RedirectResponse
    {
        if ($order->isPaid()) {
            return back()->withErrors(['order' => __('orders.cannot_cancel_paid')]);
        }

        if ($order->status === 'cancelled') {
            return back()->with('warning', __('orders.already_cancelled'));
        }

        // Recorded before the status changes, because it is the reason the
        // message below is worded the way it is: the kitchen may already be
        // cooking this, and the app cannot un-print a ticket.
        $reachedTheKitchen = $order->printJobs()
            ->where('kind', PrintJob::KIND_KITCHEN)
            ->where('status', PrintJob::PRINTED)
            ->exists();

        $order->update(['status' => 'cancelled']);

        Log::info("Order {$order->reference} cancelled by user {$request->user()?->id}.");

        $this->safeBroadcast(new OrderStatusUpdated($order->fresh()), toOthers: true);

        return back()->with(
            $reachedTheKitchen ? 'warning' : 'success',
            $reachedTheKitchen ? __('orders.cancelled_after_printing') : __('orders.cancelled'),
        );
    }

    /**
     * Moves a running order to a different table — a customer's tab
     * relocating (table was empty, simple reassignment) or two parties
     * joining up (target table already has an order, so this one's items
     * are folded into it instead). A paid order is refused on either side:
     * combining paid/unpaid state across two bills is ambiguous, so that
     * has to be settled as a fresh table rather than moved or merged.
     */
    public function moveTable(Request $request, Order $order, KitchenPrinterService $printer): RedirectResponse
    {
        $data = $request->validate([
            'table_id' => ['required', 'integer', Rule::exists('restaurant_tables', 'id')->where('is_active', true)],
        ]);

        if ((int) $data['table_id'] === $order->table_id) {
            return back()->withErrors(['table_id' => __('orders.move_table_same')]);
        }

        if ($order->isPaid()) {
            return back()->withErrors(['table_id' => __('orders.cannot_move_paid')]);
        }

        $targetTable = RestaurantTable::findOrFail($data['table_id']);

        // Broader than RestaurantTable::activeOrder(): a completed-but-unpaid
        // order still "occupies" the table for merge purposes, same as the
        // cashier floor plan's own occupancy rule (see CashierController) —
        // otherwise moving an order onto that table would silently orphan
        // the unpaid one instead of merging into it.
        $destinationOrder = Order::query()
            ->where('table_id', $targetTable->id)
            ->where('id', '!=', $order->id)
            ->where(fn ($q) => $q->whereIn('status', config('orders.active_statuses'))->orWhereNull('paid_at'))
            ->whereNotIn('status', ['cancelled'])
            ->latest()
            ->first();

        if ($destinationOrder === null) {
            $order->update(['table_id' => $targetTable->id]);
            $this->safeBroadcast(new OrderStatusUpdated($order->fresh()), toOthers: true);

            return back()->with('success', __('orders.table_moved'));
        }

        if ($destinationOrder->isPaid()) {
            return back()->withErrors(['table_id' => __('orders.cannot_merge_into_paid')]);
        }

        DB::transaction(function () use ($order, $destinationOrder) {
            $order->items()->update(['order_id' => $destinationOrder->id]);
            $destinationOrder->increment('discount_cents', $order->discount_cents ?? 0);
            $destinationOrder->increment('total_cents', $order->total_cents);
            $order->update(['status' => 'cancelled']);
        });

        $destinationOrder->refresh();
        $this->safeBroadcast(new OrderStatusUpdated($order), toOthers: true);
        $this->safeBroadcast(new OrderStatusUpdated($destinationOrder), toOthers: true);
        // Reprints the destination's whole running ticket, same trade-off as
        // WaiterOrderController::addItems() — acceptable since kitchen staff
        // already expect a fresh ticket to represent an order's current state.
        $printer->queuePrintJobs($destinationOrder);

        return back()->with('success', __('orders.tables_merged'));
    }

    /**
     * Dispatches the same queued job used on order creation, rather than
     * printing synchronously — so a manual reprint queued while the
     * kitchen printer is unplugged/offline still goes out automatically
     * once it reconnects (see PrintOrderTicket::retryUntil()/backoff()),
     * instead of just failing once.
     */
    public function print(Request $request, Order $order, KitchenPrinterService $printer): RedirectResponse
    {
        // requestedByHuman: somebody pressed the button. Without it this
        // was the one print path in the app with no way to say so, and the
        // dedup rules answered it with silence — an order whose ticket had
        // already come out, or whose printer had spent its attempts, could
        // not be reprinted from here at all. The reprint screen and the
        // till's own button both passed the flag; this one never did.
        $result = $printer->queuePrintJobs($order, requestedByHuman: true, userId: $request->user()?->id);

        return back()->with(...$this->printFlash($result));
    }

    /**
     * Prints the invoice to the cashier's till printer directly, instead
     * of opening the PDF for the cashier to print manually. Same queued
     * job pattern as print() above — see PrintCashierReceipt.
     */
    public function printForCashier(Request $request, Order $order, CashierReceiptPrinterService $printer): RedirectResponse
    {
        // requestedByHuman: somebody pressed the button, so an earlier
        // attempt that already gave up gets a fresh copy rather than
        // silently doing nothing.
        $result = $printer->queuePrintJobs($order, requestedByHuman: true, userId: $request->user()?->id);

        return back()->with(...$this->printFlash($result));
    }

    /**
     * The flash message for a print request, and the honest one.
     *
     * Every print button used to answer "sent to the printer" unconditionally
     * — including when no printer was configured for the station, so the
     * items were never going anywhere. Somebody watching a printer that
     * never moves has no way to tell that from one that is merely slow, and
     * the message was actively pointing them at the wrong problem.
     *
     * @return array{0: string, 1: string}
     */
    private function printFlash(PrintDispatchResult $result): array
    {
        if ($result->hasUnroutableStations()) {
            return ['error', __('orders.print_no_printer', ['stations' => $result->stationList()])];
        }

        if (! $result->queuedSomething()) {
            return ['warning', __('orders.print_nothing_to_send')];
        }

        return ['success', __('orders.print_queued')];
    }

    public function invoice(Order $order, PdfService $pdf, RestaurantSettings $branding): Response
    {
        $order->load('items.modifiers', 'table');
        $direction = app(LanguageRegistry::class)->direction(app()->getLocale());

        // Sized for an 80mm thermal receipt printer (the restaurant's actual
        // hardware), not a full sheet — width is fixed, height grows with
        // the item count so nothing gets cut off or leaves a blank tail.
        $widthMm = 80;
        $heightMm = max(100, 70 + $order->items->count() * 14);

        // mPDF embeds images by local filesystem path far more reliably
        // than by fetching the public URL over HTTP, so resolve the
        // storage disk path here rather than passing logo_url as-is.
        $logoPath = $branding->logo_path && Storage::disk('public')->exists($branding->logo_path)
            ? Storage::disk('public')->path($branding->logo_path)
            : null;

        $mpdf = $pdf->fromView('pdf.invoice-thermal', [
            'order' => $order,
            'branding' => $branding,
            'logoPath' => $logoPath,
            'currencySymbol' => $branding->currency_symbol,
            'direction' => $direction,
            'labels' => __('pdf'),
        ], $direction, [
            'format' => [$widthMm, $heightMm],
            'margin_top' => 4, 'margin_bottom' => 4, 'margin_left' => 4, 'margin_right' => 4,
        ]);

        return response($mpdf->Output($order->reference.'.pdf', Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$order->reference.'.pdf"',
        ]);
    }
}
