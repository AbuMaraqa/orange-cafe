<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // Traceability only — pricing itself is already immutable via
            // unit_price_cents/product_name snapshots on this table. If the
            // menu item is later removed from the menu (or the menu
            // deleted), the historical order line is untouched.
            $table->foreignId('menu_item_id')->nullable()->after('product_id')
                ->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('menu_item_id');
        });
    }
};
