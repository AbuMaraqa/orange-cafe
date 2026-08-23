<?php

namespace App\Http\Controllers;

use App\Services\LanguageRegistry;
use App\Settings\RestaurantSettings;
use Illuminate\Http\JsonResponse;

/**
 * The web app manifest, so a tablet can keep this on its home screen.
 *
 * Generated rather than shipped as a static file because everything in it
 * is already editable from the admin settings — the restaurant's name, its
 * two brand colours. A static manifest would go stale the first time
 * somebody renamed the place, and nothing would ever tell them.
 *
 * ## What this does and does not buy on an iPad
 *
 * Added to the home screen, the app opens without Safari's address bar and
 * toolbars: a waiter tablet shows the floor plan and nothing else, and a
 * mis-tap can no longer navigate away from it. That part works over plain
 * http, which matters because this app is reached at http://<lan-ip>:8000
 * and there is no certificate to be had for an IP on a cafe's router.
 *
 * What it does *not* buy is offline support. Service workers — the only way
 * to cache anything — are refused outside a secure context, and an IP over
 * http is not one. Nothing here pretends otherwise: there is no service
 * worker, and a tablet that loses the network shows a browser error rather
 * than a stale floor plan, which for a screen whose whole job is to be
 * current is the better failure.
 */
class WebManifestController extends Controller
{
    public function __invoke(RestaurantSettings $branding, LanguageRegistry $languages): JsonResponse
    {
        $locale = app()->getLocale();

        return response()->json([
            'name' => $branding->name,
            // What actually fits under an icon. iOS truncates around a
            // dozen characters and a restaurant's full legal name is
            // usually longer than that.
            'short_name' => $this->shortName($branding->name),
            'lang' => $locale,
            'dir' => $languages->direction($locale),
            // Not a role's screen: which one is right depends on who is
            // holding the tablet, and the root already sends each person
            // where they belong once they are signed in.
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => $branding->primary_color,
            'icons' => [
                [
                    'src' => '/icons/icon-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => '/icons/icon-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
            ],
        ], 200, [
            'Content-Type' => 'application/manifest+json',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The first word or two, capped — enough to tell this icon apart from
     * whatever else is on the tablet's home screen.
     */
    private function shortName(string $name): string
    {
        $trimmed = trim($name);

        if (mb_strlen($trimmed) <= 12) {
            return $trimmed;
        }

        $words = preg_split('/\s+/u', $trimmed) ?: [];
        $short = '';

        foreach ($words as $word) {
            $candidate = $short === '' ? $word : $short.' '.$word;

            if (mb_strlen($candidate) > 12) {
                break;
            }

            $short = $candidate;
        }

        return $short !== '' ? $short : mb_substr($trimmed, 0, 12);
    }
}
