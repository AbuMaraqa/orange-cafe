<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every existing product was attached to exactly one category — turn
     * that into the equivalent menu_items row before products.category_id
     * is dropped, so no existing product/menu data is lost. Price is left
     * null (inherit the product's own price_cents) since there was never a
     * per-menu override before this migration.
     */
    public function up(): void
    {
        $products = DB::table('products')->whereNotNull('category_id')->get(['id', 'category_id', 'is_active', 'sort_order']);
        $categoryMenuIds = DB::table('categories')->pluck('menu_id', 'id');

        $rows = $products->map(fn ($product) => [
            'menu_id' => $categoryMenuIds[$product->category_id] ?? null,
            'category_id' => $product->category_id,
            'product_id' => $product->id,
            'price_cents' => null,
            'is_active' => $product->is_active,
            'is_featured' => false,
            'sort_order' => $product->sort_order,
            'created_at' => now(),
            'updated_at' => now(),
        ])->filter(fn ($row) => $row['menu_id'] !== null)->values();

        if ($rows->isNotEmpty()) {
            DB::table('menu_items')->insert($rows->all());
        }
    }

    public function down(): void
    {
        DB::table('menu_items')->truncate();
    }
};
