<?php

namespace App\Services\Native;

/**
 * Shared by NativeAppServiceProvider (tray menu links) and
 * NativeSettingsController (the control panel) so staff can find this
 * machine's LAN address — the address every other device in the restaurant
 * (waiter tablets, the kitchen screen, a customer's phone scanning a table
 * QR code) has to reach — without duplicating the interface-detection logic.
 */
class NativeNetworkInfo
{
    /**
     * This machine's LAN-facing IPv4 addresses. Filters out loopback and
     * virtual/container interfaces that would never be reachable from
     * another device on the network.
     *
     * @return string[]
     */
    public function lanIpAddresses(): array
    {
        if (! function_exists('net_get_interfaces')) {
            return [];
        }

        $ignoredPrefixes = ['lo', 'docker', 'veth', 'br-', 'virbr', 'vmnet', 'vboxnet', 'tun', 'tap', 'utun'];
        $addresses = [];

        foreach (net_get_interfaces() ?: [] as $name => $interface) {
            $isIgnoredInterface = collect($ignoredPrefixes)
                ->contains(fn (string $prefix) => str_starts_with(strtolower($name), $prefix));

            if ($isIgnoredInterface) {
                continue;
            }

            foreach ($interface['unicast'] ?? [] as $unicast) {
                $address = $unicast['address'] ?? '';

                if (($unicast['family'] ?? null) !== 2 || $address === '' || $address === '127.0.0.1') {
                    continue;
                }

                // 169.254.x.x (APIPA/link-local) shows up whenever an
                // adapter failed to get a real DHCP lease — Windows assigns
                // one to nearly every idle/disconnected adapter, so without
                // this filter the list is mostly unreachable noise burying
                // the one real LAN address underneath it.
                if (str_starts_with($address, '169.254.')) {
                    continue;
                }

                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * The single best guess at "the address to hand out", used for the
     * default APP_URL (which is what table QR codes are built from — see
     * RestaurantTableController::qrCode).
     *
     * Prefers a private RFC1918 range, since a machine with both a LAN
     * adapter and something like a Hyper-V/WSL bridge can otherwise return
     * the virtual one first.
     */
    public function primaryLanIp(): ?string
    {
        $addresses = $this->lanIpAddresses();

        if ($addresses === []) {
            return null;
        }

        $private = array_filter($addresses, fn (string $ip) => str_starts_with($ip, '192.168.')
            || str_starts_with($ip, '10.')
            || preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $ip) === 1);

        return $private !== [] ? reset($private) : $addresses[0];
    }

    /**
     * Whether something is actually accepting TCP connections on host:port —
     * used for the status panel, since a NativePHP child process can exist
     * (per ChildProcess::get()) while its actual listener failed to bind
     * (e.g. a port conflict), which is exactly the failure mode that's easy
     * to miss otherwise.
     *
     * Every resolved address for the host is tried, not just whichever one
     * the OS hands back first. Passing "localhost" straight to fsockopen on
     * Windows dials ::1, and the servers this checks (Reverb, `artisan
     * serve`) bind 0.0.0.0 — IPv4 only. The connection then times out against
     * a perfectly healthy server, and the panel reports it as down. Since
     * REVERB_HOST=localhost is the shipped default, that is the *normal*
     * case, not an edge one.
     */
    /**
     * Is the thing answering this port actually ours?
     *
     * portOpen() below only proves *something* accepts a connection, and
     * that is how a real outage stayed invisible: Laragon's nginx had taken
     * 8080, the panel probed 127.0.0.1:8080, nginx answered, and the status
     * light went green — while every tablet dialling the LAN address got
     * "403 Forbidden — nginx" and live updates were dead all day. A check
     * that cannot tell our server from someone else's is worse than no
     * check, because it actively argues against the person reporting the
     * fault.
     *
     * The discriminator is the `Server:` header. Reverb is a ReactPHP
     * socket server and sends none; nginx, Apache and IIS all announce
     * themselves. So a named server on our port means the port is taken.
     *
     * @return array{reachable: bool, conflict: string|null}
     */
    public function identifyServer(string $host, int $port, float $timeout = 1.0): array
    {
        $connection = @fsockopen($host, $port, $errorCode, $errorMessage, $timeout);

        if ($connection === false) {
            return ['reachable' => false, 'conflict' => null];
        }

        try {
            stream_set_timeout($connection, 1);

            // A bare request is enough — we only need the status line and
            // headers, and every HTTP server answers something.
            fwrite($connection, "GET / HTTP/1.0\r\nHost: {$host}\r\nConnection: close\r\n\r\n");

            $head = '';

            // Bounded read: a websocket server that declines to answer must
            // not hang the status panel.
            while (! feof($connection) && strlen($head) < 2048) {
                $chunk = fread($connection, 512);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $head .= $chunk;

                if (str_contains($head, "\r\n\r\n")) {
                    break;
                }
            }
        } finally {
            fclose($connection);
        }

        if (preg_match('/^Server:\s*(.+)$/mi', $head, $matches)) {
            return ['reachable' => true, 'conflict' => trim($matches[1])];
        }

        return ['reachable' => true, 'conflict' => null];
    }

    public function portOpen(string $host, int $port, float $timeout = 1.0): bool
    {
        foreach ($this->candidateAddresses($host) as $address) {
            $connection = @fsockopen($address, $port, $errorCode, $errorMessage, $timeout);

            if ($connection !== false) {
                fclose($connection);

                return true;
            }
        }

        return false;
    }

    /**
     * IPv4 addresses for a hostname, most-likely-to-work first, with the
     * original host kept as a fallback so an IPv6-only or unresolvable name
     * still gets one direct attempt.
     *
     * @return string[]
     */
    private function candidateAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        return array_values(array_unique([...(gethostbynamel($host) ?: []), $host]));
    }
}
