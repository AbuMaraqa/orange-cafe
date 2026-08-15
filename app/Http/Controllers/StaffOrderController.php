<?php

namespace App\Http\Controllers;

use App\Events\OrderStatusUpdated;
use App\Http\Controllers\Concerns\BroadcastsSafely;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RestaurantTable;
use App\Services\CashierReceiptPrinterService;
use App\Services\KitchenPrinterService;
use App\Services\PdfService;
use App\Settings\RestaurantSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
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
        ]);

        $order->update([
            'paid_at' => now(),
            'paid_by' => $request->user()->id,
            'amount_paid_cents' => $data['amount_paid_cents'] ?? $order->total_cents,
        ]);

        $cashierPrinter->queueDrawerOpen();

        $this->safeBroadcast(new OrderStatusUpdated($order), toOthers: true);

        return back()->with('success', __('orders.marked_paid'));
    }

    /**
     * A customer-placed order sits "pending" and unprinted until a waiter
     * reviews and confirms it — this is that confirmation. Distinct from
     * updateStatus() (which the waiter no longer has access to) because
     * accepting a brand-new order is a different action from advancing an
     * already-accepted one through the kitchen workflow.
     */
    public function confirm(Order $order, KitchenPrinterService $printer): RedirectResponse
    {
        abort_unless($order->status === 'pending', 403);

        $order->update(['status' => 'confirmed']);

        $this->safeBroadcast(new OrderStatusUpdated($order), toOthers: true);
        $printer->queuePrintJobs($order);

        return back()->with('success', __('orders.confirmed'));
    }

    /**
     * Lets an admin correct a mis-ordered item at any point up to payment
     * (route is already role:admin-only — see routes/web.php) — once an
     * order is paid, the till total has to stay in sync with what was
     * actually collected, so removal is refused from there on, same cutoff
     * moveTable() uses.
     */
    public function removeItem(Order $order, OrderItem $item): RedirectResponse
    {
        abort_if($item->order_id !== $order->id, 404);
        abort_if($order->isPaid(), 403);

        $order->decrement('total_cents', $item->subtotal_cents);
        $item->delete();

        $this->safeBroadcast(new OrderStatusUpdated($order->fresh()), toOthers: true);

        return back()->with('success', __('orders.item_removed'));
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
    public function print(Order $order, KitchenPrinterService $printer): RedirectResponse
    {
        $printer->queuePrintJobs($order);

        return back()->with('success', __('orders.print_queued'));
    }

    /**
     * Prints the invoice to the cashier's till printer directly, instead
     * of opening the PDF for the cashier to print manually. Same queued
     * job pattern as print() above — see PrintCashierReceipt.
     */
    public function printForCashier(Order $order, CashierReceiptPrinterService $printer): RedirectResponse
    {
        $printer->queuePrintJobs($order);

        return back()->with('success', __('orders.print_queued'));
    }

    public function invoice(Order $order, PdfService $pdf, RestaurantSettings $branding): Response
    {
        $order->load('items.modifiers', 'table');
        $direction = app(\App\Services\LanguageRegistry::class)->direction(app()->getLocale());

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

        return response($mpdf->Output($order->reference.'.pdf', \Mpdf\Output\Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$order->reference.'.pdf"',
        ]);
    }
}
