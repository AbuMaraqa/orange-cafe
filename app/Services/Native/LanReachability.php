<?php

namespace App\Services\Native;

use Throwable;

/**
 * Whether any device other than this machine has actually reached the app.
 *
 * The control panel could report every service healthy while no tablet in the
 * building could load a single page, and it would have no way of knowing: it
 * probes 127.0.0.1, and the machine can always reach itself. The usual cause
 * is Windows Defender — `php.exe` binding 0.0.0.0:8000 raises a prompt on the
 * first launch, and one dismissed prompt leaves the LAN server listening
 * perfectly and firewalled off from the entire restaurant. That is the exact
 * shape of failure this panel exists to catch: everything green, nothing
 * works.
 *
 * ## Why not ask the firewall
 *
 * Because the answer cannot be trusted, and asking is worse than useless when
 * it is wrong:
 *
 *  - `netsh` prints in the language Windows is installed in. Parsing its
 *    output works on the development machine and breaks on an Arabic install
 *    — which is most of them here.
 *  - Answering "Allow" on the Windows prompt creates a rule named after
 *    `php.exe`, not after anything this app could look up. Checking for a
 *    rule of our own would therefore flag a perfectly working machine, and a
 *    permanent warning on a healthy system trains people to ignore the panel.
 *  - Enumerating every inbound rule to find one that covers the port costs
 *    445ms (measured) and still has to parse localised text.
 *
 * So this does not ask a proxy question. A request that arrived from another
 * IP is proof — of the firewall, the port, the binding, the router and the
 * cable all at once. Nothing else needs to be inferred.
 *
 * ## Cost
 *
 * One `filemtime()` per request, and a write at most once a minute. It is
 * recorded to a file rather than the cache because the cache store is the
 * database, and this reading has to stay readable exactly when the database
 * is the thing that is broken — the same reason the panel's own routes run
 * without sessions (routes/web.php).
 */
class LanReachability
{
    /**
     * How long a recorded visit stands before it is written again.
     *
     * The panel only ever renders this as "a few minutes ago", so a finer
     * resolution would buy nothing and cost a file write on a server that
     * answers one request at a time.
     */
    private const THROTTLE_SECONDS = 60;

    /**
     * Notes that a device on the network reached us, if it has been long
     * enough since the last note.
     *
     * Never throws. This runs in middleware on every request, and a full disk
     * or a permissions problem must not be able to take the restaurant's
     * ordering down to record a diagnostic.
     */
    public function record(string $ip): void
    {
        try {
            $file = $this->file();

            // The stat is the throttle: no read, no decode, no write on the
            // overwhelming majority of requests.
            if (is_file($file) && (time() - (int) @filemtime($file)) < self::THROTTLE_SECONDS) {
                return;
            }

            $directory = dirname($file);

            if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
                return;
            }

            @file_put_contents($file, json_encode(['ip' => $ip, 'at' => time()]));
        } catch (Throwable) {
            // Diagnostics are never worth a failed request.
        }
    }

    /**
     * The last device seen, or null if none ever has been.
     *
     * @return array{ip: string, at: int, seconds_ago: int}|null
     */
    public function lastSeen(): ?array
    {
        try {
            $file = $this->file();

            if (! is_file($file)) {
                return null;
            }

            $data = json_decode((string) @file_get_contents($file), true);

            if (! is_array($data) || ! isset($data['ip'], $data['at'])) {
                return null;
            }

            return [
                'ip' => (string) $data['ip'],
                'at' => (int) $data['at'],
                'seconds_ago' => max(0, time() - (int) $data['at']),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Is this request coming from the machine the app is running on?
     *
     * Loopback proves nothing — it is the control window, the panel's own
     * status poll, and the operator opening the menu to check it looks right.
     * Only another device answers the question this class exists to answer.
     */
    public function isLoopback(?string $ip): bool
    {
        return $ip === null || $ip === '' || $ip === '127.0.0.1' || $ip === '::1';
    }

    private function file(): string
    {
        return storage_path('framework/last-lan-client.json');
    }
}
