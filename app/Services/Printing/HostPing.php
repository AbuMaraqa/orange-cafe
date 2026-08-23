<?php

namespace App\Services\Printing;

use Symfony\Component\Process\Process;

/**
 * Asks whether a device is on the network without saying anything to it.
 *
 * The status sweep used to answer that by opening a TCP connection to the
 * printer's port 9100 and closing it again without sending anything. A
 * thermal printer treats an opened-and-abandoned session as an interrupted
 * print job, and says so the only way it can: it beeps. Once a minute, per
 * printer, all day — a cafe listening to a printer alarm every sixty
 * seconds for a health check nobody asked to hear.
 *
 * ICMP never reaches the print port, so the printer has no idea it was
 * asked anything. That is the whole point.
 *
 * What this gives up, stated plainly: a reply proves the device is powered
 * on and on the network, not that its print port is accepting work. That is
 * a fair trade, because the print port is checked properly by the only
 * thing that truly needs to know — an actual print, which records the
 * outcome either way (see PrinterTransport::send). This is the idle
 * heartbeat between prints, and an idle heartbeat has no business making
 * noise in the room.
 */
class HostPing
{
    /** A printer on the same LAN answers in single-digit milliseconds. */
    private const TIMEOUT_MS = 800;

    /**
     * Two echo requests, not one.
     *
     * ICMP is allowed to be lost and on wifi it is: one of these printers
     * answered the second and third attempt in 1–6ms after dropping the
     * first, and a one-packet check would have reported a working printer as
     * offline on the strength of a single lost packet. A reply to either
     * packet is a reply.
     */
    private const ATTEMPTS = 2;

    public function reachable(?string $host): bool
    {
        if (blank($host)) {
            return false;
        }

        $process = new Process($this->command($host));
        // Generous next to TIMEOUT_MS: this bounds the *process*, so a ping
        // binary that hangs cannot stall the sweep behind it.
        $process->setTimeout(5);
        $process->run();

        // Exit code alone is not enough on Windows: ping there exits 0 for
        // "Destination host unreachable", which is a reply from the router
        // saying the device is absent. The reply line is what distinguishes
        // them, and it is the only thing both platforms agree on.
        return $process->isSuccessful()
            && (bool) preg_match('/ttl[=\s]*\d+/i', $process->getOutput());
    }

    /** @return array<int, string> */
    private function command(string $host): array
    {
        if (str_starts_with(strtolower(PHP_OS_FAMILY), 'win')) {
            return ['ping', '-n', (string) self::ATTEMPTS, '-w', (string) self::TIMEOUT_MS, $host];
        }

        // -W is seconds here, and must be at least 1.
        return ['ping', '-c', (string) self::ATTEMPTS, '-W', (string) max(1, (int) ceil(self::TIMEOUT_MS / 1000)), $host];
    }
}
