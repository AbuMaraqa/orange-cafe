<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tells a size from an extra.
 *
 * Every modifier group priced the same way until now: the chosen option's
 * price is *added* to the product's own. That is right for "extra cheese"
 * and wrong for "small / medium / large", where the size chosen **is** the
 * price rather than a surcharge on top of one.
 *
 * With only the additive rule available, a burger sold in three sizes left
 * the cafe two bad options, and both of them reached the customer:
 *
 *  - give the product a real base price, and the menu shows 25 while the
 *    basket shows 60 once a size is picked, because the size was added to
 *    a base that was already a size;
 *  - give the product a base price of 0 so the sizes stand alone, and the
 *    menu card advertises the burger at 0.
 *
 * A variation group is the third option: exactly one choice, and its price
 * replaces the base instead of adding to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modifier_groups', function (Blueprint $table) {
            $table->boolean('is_variation')
                ->default(false)
                ->after('is_required')
                ->comment('the chosen option sets the price instead of adding to it');
        });
    }

    public function down(): void
    {
        Schema::table('modifier_groups', function (Blueprint $table) {
            $table->dropColumn('is_variation');
        });
    }
};
