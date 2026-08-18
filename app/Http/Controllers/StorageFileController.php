<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a file from the `public` disk over HTTP.
 *
 * This is the fallback path for the packaged desktop app, where the
 * public/storage symlink can't be relied on — NativePHP moves the storage
 * directory to a per-user application-data folder at boot, so a link baked
 * in at build time points somewhere that doesn't exist on the customer's
 * machine. Menu photos and the restaurant logo are the visible casualties,
 * on the customer-facing menu above all.
 *
 * On a normal web deployment the web server serves the symlinked file before
 * Laravel ever sees the request, so this route is dead code there — which is
 * exactly the point: one behaviour, no per-environment branching.
 */
class StorageFileController extends Controller
{
    /**
     * Menu photos change only when the owner replaces them, and the customer
     * menu loads a lot of them on a phone over café Wi-Fi.
     */
    private const CACHE_SECONDS = 86400;

    public function __invoke(Request $request, string $path): Response
    {
        // `where('path', '.*')` matches slashes, so a request for
        // "..%2F..%2F.env" arrives here as a real traversal attempt. Laravel
        // decodes it before we see it, so the check has to be on the decoded
        // value.
        if (str_contains($path, '..') || str_starts_with($path, '/')) {
            abort(404);
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            abort(404);
        }

        // A conditional GET saves re-sending a few hundred KB of photos on
        // every menu view; phones revisit the menu constantly during a
        // single sitting.
        $lastModified = $disk->lastModified($path);
        $etag = '"'.md5($path.$lastModified).'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, [
                'Cache-Control' => 'public, max-age='.self::CACHE_SECONDS,
                'Etag' => $etag,
            ]);
        }

        return $disk->response($path, null, [
            'Cache-Control' => 'public, max-age='.self::CACHE_SECONDS,
            'Etag' => $etag,
        ]);
    }
}
