<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the authenticated user's stored locale preference in sync with
 * whatever locale the current URL resolved to, so a waiter who switches to
 * English on one device sees English again next time they sign in anywhere.
 */
class SyncUserLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $current = App::getLocale();

        if ($user && $user->locale !== $current) {
            $user->forceFill(['locale' => $current])->saveQuietly();
        }

        return $next($request);
    }
}
