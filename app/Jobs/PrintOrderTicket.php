<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\KitchenPrinterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued so a slow or unreachable kitchen printer never delays the
 * customer's checkout response. Requires a queue worker running
 * (`php artisan queue:work`) to actually fire.
 */
class PrintOrderTicket implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public Order $order)
    {
    }

    public function handle(KitchenPrinterService $printer): void
    {
        $printer->printOrder($this->order);
    }
}
