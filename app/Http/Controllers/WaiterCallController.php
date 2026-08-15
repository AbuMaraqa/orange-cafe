<?php

namespace App\Http\Controllers;

use App\Enums\WaiterCallStatus;
use App\Events\WaiterCallAcknowledged;
use App\Events\WaiterCallRequested;
use App\Http\Controllers\Concerns\BroadcastsSafely;
use App\Models\RestaurantTable;
use App\Models\Service;
use App\Models\WaiterCall;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class WaiterCallController extends Controller
{
    use BroadcastsSafely;

    /**
     * Customer-facing: raised from the table's menu page, never requires
     * authentication — same trust model as placing an order.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'table_id' => ['nullable', 'integer', 'exists:restaurant_tables,id'],
            'table_number' => ['nullable', 'string', 'max:50'],
            // Null keeps the plain generic "call waiter" behavior this
            // once was — a specific service is optional, not required.
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
        ]);

        $call = WaiterCall::create([
            'table_id' => $data['table_id'] ?? null,
            'table_number' => $data['table_number'] ?? null,
            'service_id' => $data['service_id'] ?? null,
            'status' => WaiterCallStatus::Pending,
        ]);

        $this->safeBroadcast(new WaiterCallRequested($call));

        return back()->with('success', __('menu.waiter_called'));
    }

    /**
     * Scanned from a per-table, per-service QR sticker (e.g. a "charcoal"
     * QR on a shisha table) — fires the same real-time alert as the
     * in-menu services sheet, but skips straight to it instead of
     * requiring the customer to open the menu and find it in the sheet.
     * A short window collapses repeat scans of the same sticker into the
     * call already in flight, rather than spamming the waiter board with
     * duplicates every time a phone re-reads the code.
     */
    public function quick(RestaurantTable $table, Service $service): InertiaResponse
    {
        $call = WaiterCall::query()
            ->where('table_id', $table->id)
            ->where('service_id', $service->id)
            ->where('status', WaiterCallStatus::Pending)
            ->where('created_at', '>=', now()->subMinutes(2))
            ->latest()
            ->first();

        if (! $call) {
            $call = WaiterCall::create([
                'table_id' => $table->id,
                'service_id' => $service->id,
                'status' => WaiterCallStatus::Pending,
            ]);

            $this->safeBroadcast(new WaiterCallRequested($call));
        }

        return Inertia::render('Customer/ServiceRequested', [
            'service' => [
                'name' => $service->name,
                'icon' => $service->icon,
                'image_url' => $service->image_url,
                'color' => $service->displayColor(),
            ],
            'tableLabel' => $table->label,
            'menuUrl' => route('menu.index', ['table_id' => $table->id, 'table_label' => $table->label]),
        ]);
    }

    /**
     * Staff-facing: dismiss a call once a waiter is on their way.
     */
    public function acknowledge(WaiterCall $call): RedirectResponse
    {
        $call->update(['status' => WaiterCallStatus::Acknowledged]);

        $this->safeBroadcast(new WaiterCallAcknowledged($call));

        return back();
    }
}
