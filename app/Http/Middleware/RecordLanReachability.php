<?php

namespace App\Http\Middleware;

use App\Services\Native\LanReachability;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notes that a device on the restaurant's network reached the app, so the
 * control panel can say whether anything out there ever has.
 *
 * See LanReachability for why this is measured rather than inferred from the
 * firewall. In short: the panel probes 127.0.0.1, which always answers, so a
 * server that is listening perfectly and blocked from the whole building
 * looks identical to a healthy one.
 *
 * Placed at the end of the `web` group, and doing nothing at all for loopback
 * — the control window, the status poll and the operator's own browser are
 * this machine talking to itself and prove nothing. For everyone else it is
 * one filemtime() and, at most once a minute, a small write.
 */
class RecordLanReachability
{
    public function __construct(private LanReachability $reachability) {}

    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->ip();

        if (! $this->reachability->isLoopback($ip)) {
            $this->reachability->record($ip);
        }

        return $next($request);
    }
}
