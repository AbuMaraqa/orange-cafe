<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The native control-panel routes skip the usual `auth` middleware — the
 * window opens at boot, before any staff account exists to log in with — so
 * without this they'd be reachable by anyone on the restaurant's network
 * through the LAN server (NativeAppServiceProvider binds it to 0.0.0.0),
 * including a customer on the guest Wi-Fi. That panel can rewrite .env and
 * restart the server for the whole building.
 *
 * Restricting it to loopback puts it at the same trust level as editing the
 * .env file directly on that machine: you have to be sitting at it.
 */
class EnsureRequestIsLocal
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->ip(), ['127.0.0.1', '::1'], true)) {
            abort(403);
        }

        return $next($request);
    }
}
