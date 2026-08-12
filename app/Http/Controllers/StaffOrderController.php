<?php

namespace App\Http\Controllers;

use App\Events\OrderStatusUpdated;
use App\Http\Controllers\Concerns\BroadcastsSafely;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\PdfService;
use App\Settings\RestaurantSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function markPaid(Request $request, Order $order): RedirectResponse
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

        $this->safeBroadcast(new OrderStatusUpdated($order), toOthers: true);

        return back()->with('success', __('orders.marked_paid'));
    }

    /**
     * A pending order hasn't been sent to the kitchen as final yet, so a
     * waiter or cashier can still correct a mis-ordered item — once it
     * moves past pending, removal has to go through a manager instead
     * (out of scope here) since the kitchen may already be preparing it.
     */
    public function removeItem(Order $order, OrderItem $item): RedirectResponse
    {
        abort_if($item->order_id !== $order->id, 404);
        abort_unless($order->status === 'pending', 403);

        $order->decrement('total_cents', $item->subtotal_cents);
        $item->delete();

        $this->safeBroadcast(new OrderStatusUpdated($order->fresh()), toOthers: true);

        return back()->with('success', __('orders.item_removed'));
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

        $mpdf = $pdf->fromView('pdf.invoice-thermal', [
            'order' => $order,
            'branding' => $branding,
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
