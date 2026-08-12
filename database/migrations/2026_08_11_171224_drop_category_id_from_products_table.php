<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Products are no longer hard-linked to a single category/menu — that
     * relationship now lives entirely in menu_items (see
     * migrate_products_to_menu_items, which already copied every existing
     * link over before this drops the old column).
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });
    }

    /**
     * Note: this restores the column but not the original data — the
     * source of truth for product/category links is menu_items now.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
    }
};
