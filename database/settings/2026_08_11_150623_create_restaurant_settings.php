<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('restaurant.name', 'Orange');
        $this->migrator->add('restaurant.tagline', null);
        $this->migrator->add('restaurant.address', null);
        $this->migrator->add('restaurant.logo_path', null);
        $this->migrator->add('restaurant.cover_image_path', null);
        $this->migrator->add('restaurant.primary_color', '#111827');
        $this->migrator->add('restaurant.secondary_color', '#f59e0b');
        $this->migrator->add('restaurant.font_family', 'tajawal');
        $this->migrator->add('restaurant.currency_code', 'USD');
        $this->migrator->add('restaurant.currency_symbol', '$');
        $this->migrator->add('restaurant.phone', null);
        $this->migrator->add('restaurant.whatsapp', null);
        $this->migrator->add('restaurant.instagram', null);
        $this->migrator->add('restaurant.facebook', null);
    }
};
