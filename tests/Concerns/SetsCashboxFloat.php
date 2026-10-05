<?php

namespace Tests\Concerns;

use App\Services\Accounting\BusinessDay;
use App\Services\Accounting\Cashbox;
use App\Services\Accounting\DayCloser;
use App\Settings\RestaurantSettings;

/**
 * What the drawer opens on every morning — 500 out of the box.
 *
 * Tests that check one rule at a time set it to zero so their figures read
 * as the rule and nothing else; the float itself is asserted in
 * CashboxOpensOnTheFloatTest.
 */
trait SetsCashboxFloat
{
    /** The value the settings migration installs. */
    protected const DEFAULT_FLOAT_CENTS = 50000;

    protected function floatOf(int $cents): void
    {
        $settings = app(RestaurantSettings::class);
        $settings->cashbox_float_cents = $cents;
        $settings->save();

        // The settings object and everything holding it live for the whole
        // request, and a test changes the setting part-way through one.
        app()->forgetInstance(RestaurantSettings::class);
        app()->forgetInstance(BusinessDay::class);
        app()->forgetInstance(Cashbox::class);
        app()->forgetInstance(DayCloser::class);
    }
}
