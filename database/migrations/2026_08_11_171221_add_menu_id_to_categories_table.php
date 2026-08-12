<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            // A section (category) belongs to exactly one menu. Nullable at
            // the schema level (kept simple/portable across DB drivers);
            // enforced as required by StoreCategoryRequest going forward.
            $table->foreignId('menu_id')->nullable()->after('parent_id')->constrained()->cascadeOnDelete();
        });

        // Existing categories predate the menu concept — fold them into a
        // default "Main Menu" instead of orphaning them, so nothing already
        // built (or seeded) breaks.
        if (DB::table('categories')->exists()) {
            $mainMenuId = DB::table('menus')->insertGetId([
                'slug' => 'main-menu',
                'publish_status' => 'published',
                'is_active' => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('menu_translations')->insert([
                ['menu_id' => $mainMenuId, 'locale' => 'ar', 'name' => 'القائمة الرئيسية', 'created_at' => now(), 'updated_at' => now()],
                ['menu_id' => $mainMenuId, 'locale' => 'en', 'name' => 'Main Menu', 'created_at' => now(), 'updated_at' => now()],
            ]);

            DB::table('categories')->update(['menu_id' => $mainMenuId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('menu_id');
        });
    }
};
