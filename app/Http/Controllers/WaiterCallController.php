<?php

namespace App\Http\Controllers;

use App\Enums\WaiterCallStatus;
use App\Events\WaiterCallAcknowledged;
use App\Events\WaiterCallRequested;
use App\Http\Controllers\Concerns\BroadcastsSafely;
use App\Models\WaiterCall;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
        ]);

        $call = WaiterCall::create([
            'table_id' => $data['table_id'] ?? null,
            'table_number' => $data['table_number'] ?? null,
            'status' => WaiterCallStatus::Pending,
        ]);

        $this->safeBroadcast(new WaiterCallRequested($call));

        return back()->with('success', __('menu.waiter_called'));
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
