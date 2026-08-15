<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('restaurant.wifi_ssid', null);
        $this->migrator->add('restaurant.wifi_password', null);
    }
};
