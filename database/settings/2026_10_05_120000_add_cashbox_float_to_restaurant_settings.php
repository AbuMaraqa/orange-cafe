<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * 500 in the till's currency, in cents — what the owner leaves in the
     * drawer each night for change. See Cashbox::openingCents().
     */
    public function up(): void
    {
        $this->migrator->add('restaurant.cashbox_float_cents', 50000);
    }
};
