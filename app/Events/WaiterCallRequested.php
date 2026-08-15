<?php

namespace App\Events;

use App\Models\WaiterCall;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * A customer tapped "Call Waiter" on their table's menu page. Broadcast on
 * the same `orders` channel staff dashboards already subscribe to, so a
 * waiter sees the alert appear instantly without any extra channel to wire
 * up or authorize.
 */
class WaiterCallRequested implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(public WaiterCall $call)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('orders')];
    }

    public function broadcastAs(): string
    {
        return 'waiter-call.requested';
    }

    public function broadcastWith(): array
    {
        $this->call->loadMissing('service');

        return [
            'call' => [
                'id' => $this->call->id,
                'table_label' => $this->call->tableLabel(),
                'service_name' => $this->call->service?->name,
                'service_icon' => $this->call->service?->icon,
                'service_image_url' => $this->call->service?->image_url,
                'service_color' => $this->call->service?->displayColor(),
                'created_at' => $this->call->created_at->toIso8601String(),
            ],
        ];
    }
}
