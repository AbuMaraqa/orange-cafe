<?php

namespace App\Events;

use App\Models\WaiterCall;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * One staff member acknowledged a waiter call — broadcast so it clears
 * from every other staff dashboard too, not just the one that handled it.
 */
class WaiterCallAcknowledged implements ShouldBroadcastNow
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
        return 'waiter-call.acknowledged';
    }

    public function broadcastWith(): array
    {
        return ['call' => ['id' => $this->call->id]];
    }
}
