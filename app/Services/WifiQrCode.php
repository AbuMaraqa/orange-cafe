<?php

namespace App\Services;

use App\Settings\RestaurantSettings;

/**
 * The "join our Wi-Fi" QR code, printed as its own sign from the restaurant
 * settings page.
 *
 * Deliberately independent of tables: the network is a property of the
 * venue, not of table 7, and one sign by the door or on the counter serves
 * the whole room. It used to be generated inside RestaurantTableController
 * and stamped onto every table sign, which meant reprinting every table in
 * the restaurant just to change the Wi-Fi password.
 *
 * A phone's camera reads a QR encoding the `WIFI:` URI as "join this
 * network" directly — which is also why it can never be folded into the menu
 * QR: one scan does one thing.
 */
class WifiQrCode
{
    public function __construct(private QrCodeService $qrCodeService) {}

    public function isConfigured(RestaurantSettings $settings): bool
    {
        return filled($settings->wifi_ssid);
    }

    /**
     * Base64 PNG, or null when no network has been configured.
     *
     * No logo is composited into this one (unlike the menu QR): the WPA
     * fields make for a denser module grid, and punching a hole in the
     * middle of it costs error-correction budget that the payload needs to
     * stay reliably scannable.
     */
    public function png(RestaurantSettings $settings, int $size = 600): ?string
    {
        if (! $this->isConfigured($settings)) {
            return null;
        }

        return base64_encode($this->qrCodeService->generatePng($this->payload($settings), size: $size));
    }

    /**
     * The WIFI: URI, per the de-facto format Android and iOS both implement.
     *
     * The separators `\ ; , : "` carry meaning inside the payload, so an
     * SSID or password containing any of them has to be escaped — a password
     * with a semicolon in it would otherwise terminate the field early and
     * hand the phone a silently wrong network.
     */
    public function payload(RestaurantSettings $settings): string
    {
        $escape = fn (?string $value) => addcslashes((string) $value, '\;,:"');

        $password = (string) ($settings->wifi_password ?? '');

        // An open network is `T:nopass` with no password field; sending
        // `T:WPA;P:;` instead makes some phones prompt for a password that
        // does not exist.
        $type = $password === '' ? 'nopass' : 'WPA';

        return 'WIFI:T:'.$type.';S:'.$escape($settings->wifi_ssid).';P:'.$escape($password).';;';
    }
}
