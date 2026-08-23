<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A line the kitchen must never see.
     *
     * Charging for a long-held table is a decision at the till; printing it
     * at the pass puts a chef in front of a ticket for something that
     * cannot be cooked, on the same paper as food that can — which is how a
     * real dish gets missed. The flag is on the line rather than read from
     * the service charge, for the same reason the name and the price are
     * copied here: a line has to keep meaning what it meant when it was
     * added, whatever is edited or deleted afterwards.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('service_charge_id')->nullable()->after('product_id')
                ->constrained()->nullOnDelete();
            $table->boolean('prints_to_kitchen')->default(true)->after('station');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_charge_id');
            $table->dropColumn('prints_to_kitchen');
        });
    }
};
