<?php

namespace App\Services\Printing;

use App\Models\Printer;
use App\Services\Native\NativeNetworkInfo;
use Symfony\Component\Process\Process;

/**
 * Finds the thermal printers on this network, so nobody has to type an IP
 * address.
 *
 * Adding a printer needs its address, and nobody in a cafe knows what that
 * is: it is not written on the printer, the router hands it out and changes
 * it, and reading it means printing a self-test page from a button
 * combination that differs per model. That one question is enough to end an
 * install with the software working perfectly and not a single printer
 * configured — which is exactly what happened on the second machine.
 *
 * ## Why the ARP table and not a subnet sweep
 *
 * The obvious approach is to try all 254 addresses. It was tried, and on
 * Windows PHP it does not work: `STREAM_CLIENT_ASYNC_CONNECT` still blocks
 * per call, so a sweep took 132 seconds and found none of the three
 * printers that answer a direct connect in under 0.15s. Opening the sockets
 * in parallel instead exhausts the connection table and hangs.
 *
 * The machine already knows who is on the network. Every device it has
 * exchanged a packet with is in the ARP table, which `arp -a` prints
 * instantly — seventeen addresses here instead of two hundred and
 * fifty-four. Probing only those is both faster and more accurate than any
 * sweep.
 *
 * The trade-off, stated plainly: a printer the machine has never talked to
 * is not in the table. In practice a printer that is switched on and on the
 * network gets there on its own (DHCP, ARP announcements), and anything
 * this misses can still be typed in by hand.
 */
class PrinterDiscovery
{
    /** The port every ESC/POS network printer answers on. */
    public const PRINTER_PORT = 9100;

    /**
     * Per-address probe budget. Real printers on this network answer in
     * 0.01–0.15s, so this is generous; the point of keeping it short is
     * that the handful of non-printers in the table fail fast.
     */
    private const PROBE_TIMEOUT_SECONDS = 0.4;

    /**
     * Manufacturer prefixes worth naming in the list. Not a filter — an
     * unrecognised device that answers on 9100 is still almost certainly a
     * printer — just a hint that makes the right row obvious.
     */
    private const KNOWN_VENDORS = [
        '00:00:48' => 'Epson',
        '00:26:ab' => 'Seiko Epson',
        '00:1b:a9' => 'Brother',
        '00:80:77' => 'Brother',
        '00:0d:4b' => 'Star Micronics',
        '00:11:62' => 'Star Micronics',
        '00:01:e6' => 'HP',
        '00:15:99' => 'Bixolon',
    ];

    public function __construct(private NativeNetworkInfo $network) {}

    /**
     * @return array<int, array{ip: string, port: int, vendor: string|null, already_added: bool, name: string|null}>
     */
    public function scan(): array
    {
        $taken = Printer::query()
            ->whereNotNull('ip_address')
            ->pluck('name', 'ip_address');

        $found = [];

        foreach ($this->neighbours() as $ip => $mac) {
            $vendor = $this->vendorFor($mac);
            $reachable = $this->answersOnPrinterPort($ip);

            // A known printer vendor is listed even when the probe fails.
            //
            // These printers accept one TCP connection at a time, and this
            // app is already using it — the scheduled status sweep and the
            // print queue both connect, and a probe landing in between is
            // refused. Requiring a successful probe therefore hid the three
            // working printers on this very network while listing nothing
            // else, which is the worst possible answer: it says "you have no
            // printers" to someone looking straight at them.
            //
            // So the hardware address decides what to *offer*, and the probe
            // only decides what to *say about it*.
            if (! $reachable && $vendor === null) {
                continue;
            }

            $found[] = [
                'ip' => $ip,
                'port' => self::PRINTER_PORT,
                'vendor' => $vendor,
                'reachable' => $reachable,
                // Shown rather than hidden: seeing the printers it already
                // has is how somebody confirms the search worked at all.
                'already_added' => $taken->has($ip),
                'name' => $taken->get($ip),
            ];
        }

        return $found;
    }

    /**
     * Every address on our own subnets that this machine has spoken to,
     * mapped to its hardware address.
     *
     * Filtered to our subnets because the ARP table can hold entries for
     * other interfaces, and a sweep has no business reaching past the
     * network it was asked about.
     *
     * @return array<string, string> ip => mac
     */
    private function neighbours(): array
    {
        $prefixes = $this->subnetPrefixes();

        if ($prefixes === []) {
            return [];
        }

        $process = new Process(['arp', '-a']);
        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful()) {
            return [];
        }

        $neighbours = [];

        foreach (preg_split('/\R/', $process->getOutput()) as $line) {
            if (! preg_match('/(\d{1,3}(?:\.\d{1,3}){3})\s+([0-9a-f]{2}(?:[:-][0-9a-f]{2}){5})/i', $line, $m)) {
                continue;
            }

            [, $ip, $mac] = $m;

            $onOurNetwork = collect($prefixes)->contains(fn (string $p) => str_starts_with($ip, $p));

            // Broadcast and multicast rows are in the table too and answer
            // nothing; skipping them keeps the probe list honest.
            if (! $onOurNetwork || str_ends_with($ip, '.255')) {
                continue;
            }

            $neighbours[$ip] = strtolower(str_replace('-', ':', $mac));
        }

        ksort($neighbours, SORT_NATURAL);

        return $neighbours;
    }

    /** @return array<int, string> e.g. ['192.168.1.'] */
    private function subnetPrefixes(): array
    {
        $prefixes = [];

        foreach ($this->network->lanIpAddresses() as $ip) {
            $parts = explode('.', $ip);

            if (count($parts) === 4) {
                $prefixes[] = $parts[0].'.'.$parts[1].'.'.$parts[2].'.';
            }
        }

        return array_values(array_unique($prefixes));
    }

    private function answersOnPrinterPort(string $ip): bool
    {
        $socket = @fsockopen($ip, self::PRINTER_PORT, $errno, $errstr, self::PROBE_TIMEOUT_SECONDS);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    private function vendorFor(string $mac): ?string
    {
        return self::KNOWN_VENDORS[substr($mac, 0, 8)] ?? null;
    }
}
