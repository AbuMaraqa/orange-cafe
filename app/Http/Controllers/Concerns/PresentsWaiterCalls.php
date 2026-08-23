<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\WaiterCallStatus;
use App\Models\WaiterCall;
use Illuminate\Support\Collection;

/**
 * The tables currently asking for someone, for any staff screen that
 * should hear about it.
 *
 * Shared rather than repeated because three screens now show these and the
 * shape of the payload is what the Vue side listens for over Reverb: a
 * screen given a slightly different set of keys would render the live
 * updates and the initial load differently, which is the kind of bug that
 * only appears once a real call comes in.
 */
trait PresentsWaiterCalls
{
    /**
     * Oldest first — whoever has waited longest is the most urgent, and the
     * list doubles as the queue to work through.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function pendingWaiterCalls(): Collection
    {
        return WaiterCall::query()
            ->with('restaurantTable', 'service')
            ->where('status', WaiterCallStatus::Pending)
            ->oldest()
            ->get()
            ->map(fn (WaiterCall $call) => [
                'id' => $call->id,
                'table_label' => $call->tableLabel(),
                'service_name' => $call->service?->name,
                'service_icon' => $call->service?->icon,
                'service_image_url' => $call->service?->image_url,
                'service_color' => $call->service?->displayColor(),
                'created_at' => $call->created_at->toIso8601String(),
            ]);
    }
}
