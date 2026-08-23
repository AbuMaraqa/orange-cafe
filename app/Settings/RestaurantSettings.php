<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Restaurant branding shown on the public customer menu, editable from the
 * admin panel without touching code. Backed by spatie/laravel-settings
 * (a single row per property in the `settings` table), not a translatable
 * Eloquent model — the restaurant's name/address don't need per-locale
 * variants, so it stays a plain typed settings object.
 */
class RestaurantSettings extends Settings
{
    public string $name;

    public ?string $tagline;

    public ?string $address;

    public ?string $logo_path;

    public ?string $cover_image_path;

    public string $primary_color;

    public string $secondary_color;

    public string $font_family;

    public string $currency_code;

    public string $currency_symbol;

    public ?string $phone;

    public ?string $whatsapp;

    public ?string $instagram;

    public ?string $facebook;

    public ?string $wifi_ssid;

    public ?string $wifi_password;

    public ?string $receipt_footer_text;

    /**
     * The clock reading a trading day starts at, "HH:MM".
     *
     * A café that serves until three in the morning has one night that two
     * calendar dates disagree about: the drawer is counted once, at 3am, but
     * three hours of what it holds were taken after midnight and are filed
     * under a day that has barely begun. Set this to "06:00" and everything
     * before six belongs to the day before — the day the staff worked, and
     * the one the drawer was counted for.
     *
     * "00:00" is the calendar day, which is what every figure meant before
     * this setting existed. See App\Services\Accounting\BusinessDay.
     */
    public string $business_day_starts_at;

    public static function group(): string
    {
        return 'restaurant';
    }
}
