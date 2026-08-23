<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A discount agreed at the till, recorded on the order rather than smeared
 * across its lines.
 *
 * Repricing individual items could already take money off a bill, but it
 * loses what actually happened: the kitchen record then says the coffee
 * cost 8 when it costs 12, and the day's figures can't tell a discount from
 * a menu price. Keeping it as its own number means the lines stay honest
 * and the reduction stays visible.
 *
 * Additive only — existing orders get 0, and nothing else on the table is
 * touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('discount_cents')->default(0)->after('total_cents');
            $table->string('discount_reason')->nullable()->after('discount_cents');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['discount_cents', 'discount_reason']);
        });
    }
};
