<?php

namespace App\Http\Controllers;

use App\Events\OrderStatusUpdated;
use App\Models\Order;
use App\Services\PdfService;
use App\Settings\RestaurantSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class StaffOrderController extends Controller
{
    public function updateStatus(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(config('orders.statuses')))],
        ]);

        $order->update(['status' => $data['status']]);

        $this->safeBroadcast(new OrderStatusUpdated($order));

        return back()->with('success', __('orders.status_updated'));
    }

    public function markPaid(Order $order): RedirectResponse
    {
        $order->update(['paid_at' => now()]);

        $this->safeBroadcast(new OrderStatusUpdated($order));

        return back()->with('success', __('orders.marked_paid'));
    }

    public function invoice(Order $order, PdfService $pdf, RestaurantSettings $branding): Response
    {
        $order->load('items.modifiers', 'table');
        $direction = app(\App\Services\LanguageRegistry::class)->direction(app()->getLocale());

        $mpdf = $pdf->fromView('pdf.invoice', [
            'order' => $order,
            'branding' => $branding,
            'currencySymbol' => $branding->currency_symbol,
            'direction' => $direction,
            'labels' => __('pdf'),
        ], $direction, ['format' => 'A5', 'margin_top' => 10, 'margin_bottom' => 10, 'margin_left' => 10, 'margin_right' => 10]);

        return response($mpdf->Output($order->reference.'.pdf', \Mpdf\Output\Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$order->reference.'.pdf"',
        ]);
    }

    /**
     * A status update must still be saved and the page still respond even
     * if the Reverb server (or any broadcaster) is unreachable — other
     * staff screens simply won't get the live push and fall back to
     * whatever they see on next reload.
     */
    protected function safeBroadcast($event): void
    {
        try {
            broadcast($event)->toOthers();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
