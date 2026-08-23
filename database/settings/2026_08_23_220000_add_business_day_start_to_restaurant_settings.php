<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Midnight by default, which is what every existing café's figures were
     * built on. A venue that trades past midnight moves it forward; one
     * that shuts at eleven never touches it and nothing about its day
     * changes.
     */
    public function up(): void
    {
        $this->migrator->add('restaurant.business_day_starts_at', '00:00');
    }
};
