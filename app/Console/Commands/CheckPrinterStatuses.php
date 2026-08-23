<?php

namespace App\Console\Commands;

use App\Models\Printer;
use App\Services\Printing\HostPing;
use App\Services\Printing\PrinterTransport;
use Illuminate\Console\Command;
use Throwable;

/**
 * Probes every active printer so the admin printer list shows real
 * online/offline state even when no orders are being printed — without
 * this, a printer's status would only update the next time something
 * happens to be sent to it (or someone clicks "Test connection"), so a
 * printer that went offline overnight would still show "online" from
 * yesterday's last successful print.
 *
 * Each printer is probed independently: one printer erroring never stops
 * the rest of the sweep, mirroring the same per-printer isolation
 * PrintOrderTicket relies on for actual printing.
 *
 * This is also where a recovery is noticed. PrinterHealth stamps
 * recovered_at when a printer that was down answers again — which is what
 * stops the hour of tickets it missed from printing themselves. The sweep
 * deliberately does not re-dispatch anything: coming back online is a fact
 * to record, never an event that empties a queue.
 */
class CheckPrinterStatuses extends Command
{
    protected $signature = 'app:check-printer-statuses';

    protected $description = 'Probe every active printer and record whether it is currently reachable.';

    public function handle(PrinterTransport $transport, HostPing $ping): void
    {
        Printer::query()->where('is_active', true)->each(function (Printer $printer) use ($transport, $ping) {
            try {
                // A network printer is pinged, never connected to.
                //
                // This sweep used to open the printer's port 9100 and close
                // it again without sending anything. The printer reads that
                // as an interrupted print job and answers the only way it
                // can — it beeps. Once a minute, per printer, all day, for a
                // health check nobody asked to hear.
                //
                // ICMP never touches the print port, so the printer does not
                // know it was asked. What it costs is precision: a reply
                // proves the device is powered on and on the network, not
                // that it will accept work. That is the right trade for an
                // idle heartbeat, because the print port is checked properly
                // by the one thing that actually needs to know — a real
                // print, which records the outcome either way
                // (PrinterTransport::send).
                // A reply is proof; silence is not.
                //
                // This used to record the ping's answer either way, and the
                // "no" was wrong often enough to stop a cafe printing.
                // Thermal printers commonly drop ICMP altogether — three on
                // one site answered no ping at all while accepting work on
                // 9100 the whole time — so the sweep painted working
                // printers offline, a later successful print read as a
                // "recovery from an outage", and PrinterHealth then held
                // every ticket for an order placed before that instant. The
                // symptom was tickets that silently never printed; the cause
                // was an outage that never happened.
                //
                // So a ping that answers marks the printer up, and a ping
                // that does not answer changes nothing. Being declared down
                // is left to the one check that cannot be wrong about it: an
                // actual send, which PrinterTransport records either way.
                //
                // What this gives up is an idle printer that dies unnoticed
                // until somebody prints to it. That is the better failure —
                // a red light that is sometimes late beats a red light that
                // is confidently wrong and stops the kitchen.
                if ($printer->connection_type === 'network') {
                    if ($ping->reachable($printer->ip_address)) {
                        $printer->recordStatus(reachable: true);
                    }

                    return;
                }

                // USB and Windows-share printers have no port to disturb:
                // asking them costs nothing and makes no noise.
                $transport->probe($printer);
            } catch (Throwable $e) {
                // The failure is already recorded, with its reason, via
                // PrinterHealth. Swallowing it here is what keeps one dead
                // printer from ending the sweep before the rest are checked.
            }
        });
    }
}
