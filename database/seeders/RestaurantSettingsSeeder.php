<?php

namespace Database\Seeders;

use App\Settings\RestaurantSettings;
use Illuminate\Database\Seeder;

class RestaurantSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = app(RestaurantSettings::class);

        $settings->name = 'Orange';
        $settings->tagline = 'مأكولات شرقية أصيلة بلمسة عصرية';
        $settings->address = 'رام الله، فلسطين';
        $settings->primary_color = '#c2410c';
        $settings->secondary_color = '#f59e0b';
        $settings->font_family = 'tajawal';
        $settings->currency_code = 'ILS';
        $settings->currency_symbol = '₪';
        $settings->phone = '+970-59-000-0000';
        $settings->whatsapp = '+970-59-000-0000';
        $settings->instagram = 'https://instagram.com/orange';
        $settings->facebook = 'https://facebook.com/orange';

        $settings->save();
    }
}
