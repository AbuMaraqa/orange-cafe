<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Demonstrates populating the catalog from a real external data source
 * (TheMealDB's free public API — https://www.themealdb.com/api.php) instead
 * of hand-written seed data: real dish names and photos, downloaded once
 * into storage/app/public/products and referenced here. TheMealDB has no
 * Arabic localization, so the Arabic name/description below are this
 * seeder's own translation, not sourced from the API.
 */
class ImportedDishesSeeder extends Seeder
{
    public function run(): void
    {
        $mainMenu = Menu::where('slug', 'main-menu')->first();

        if (! $mainMenu) {
            return;
        }

        $grills = Category::where('slug', 'grills')->first();
        $pasta = Category::where('slug', 'pasta')->first();
        $desserts = Category::where('slug', 'desserts')->first();
        $mains = Category::where('slug', 'main-dishes')->first();

        $seafood = Category::updateOrCreate(['slug' => 'seafood'], [
            'menu_id' => $mainMenu->id, 'parent_id' => $mains?->id, 'station' => 'kitchen', 'is_active' => true, 'sort_order' => 2,
            'ar' => ['name' => 'المأكولات البحرية'], 'en' => ['name' => 'Seafood'],
        ]);

        $starters = Category::updateOrCreate(['slug' => 'starters'], [
            'menu_id' => $mainMenu->id, 'parent_id' => $mains?->id, 'station' => 'kitchen', 'is_active' => true, 'sort_order' => 3,
            'ar' => ['name' => 'المقبلات'], 'en' => ['name' => 'Starters'],
        ]);

        $dishes = [
            [
                'slug' => 'asado', 'image' => 'asado.jpg', 'price_cents' => 4800, 'section' => $grills,
                'ar' => ['name' => 'أسادو مشوي', 'description' => 'قطعة لحم بقري مشوية على الطريقة الأرجنتينية'],
                'en' => ['name' => 'Asado', 'description' => 'Argentine-style grilled beef'],
            ],
            [
                'slug' => 'aussie-burger', 'image' => 'aussie-burgers.jpg', 'price_cents' => 3800, 'section' => $grills,
                'ar' => ['name' => 'برجر أسترالي', 'description' => 'برجر لحم بقري مع إضافات كلاسيكية'],
                'en' => ['name' => 'Aussie Burger', 'description' => 'Classic beef burger, stacked high'],
            ],
            [
                'slug' => 'baked-salmon', 'image' => 'baked-salmon.jpg', 'price_cents' => 5200, 'section' => $seafood,
                'ar' => ['name' => 'سلمون مشوي بالشمر والطماطم', 'description' => 'فيليه سلمون مخبوز مع الشمر والطماطم الطازجة'],
                'en' => ['name' => 'Baked Salmon', 'description' => 'Salmon fillet baked with fennel and fresh tomatoes'],
            ],
            [
                'slug' => 'shrimp-squid-rice', 'image' => 'shrimp-squid-rice.jpg', 'price_cents' => 4600, 'section' => $seafood,
                'ar' => ['name' => 'أرز بالجمبري والحبار', 'description' => 'أرز مطهو مع الجمبري والحبار الطازج'],
                'en' => ['name' => 'Shrimp & Squid Rice', 'description' => 'Rice simmered with shrimp and fresh squid'],
            ],
            [
                'slug' => 'apple-blackberry-crumble', 'image' => 'apple-blackberry-crumble.jpg', 'price_cents' => 2200, 'section' => $desserts,
                'ar' => ['name' => 'كرامبل التفاح والعليق', 'description' => 'تفاح وعليق مخبوز تحت طبقة مقرمشة'],
                'en' => ['name' => 'Apple & Blackberry Crumble', 'description' => 'Baked apple and blackberry under a crisp crumble topping'],
            ],
            [
                'slug' => 'apple-cake', 'image' => 'apple-cake.jpg', 'price_cents' => 2000, 'section' => $desserts,
                'ar' => ['name' => 'كيك التفاح', 'description' => 'كيك طري بقطع التفاح'],
                'en' => ['name' => 'Apple Cake', 'description' => 'Soft cake studded with apple pieces'],
            ],
            [
                'slug' => 'avocado-dip', 'image' => 'avocado-dip.jpg', 'price_cents' => 1800, 'section' => $starters,
                'ar' => ['name' => 'غموس الأفوكادو مع البطاطا', 'description' => 'غموس أفوكادو كريمي يُقدّم مع بطاطا صغيرة'],
                'en' => ['name' => 'Avocado Dip with New Potatoes', 'description' => 'Creamy avocado dip served with baby potatoes'],
            ],
            [
                'slug' => 'mediterranean-pasta-salad', 'image' => 'mediterranean-pasta-salad.jpg', 'price_cents' => 3200, 'section' => $pasta,
                'ar' => ['name' => 'سلطة المعكرونة المتوسطية', 'description' => 'معكرونة باردة مع خضار متوسطية وزيت الزيتون'],
                'en' => ['name' => 'Mediterranean Pasta Salad', 'description' => 'Cold pasta tossed with Mediterranean vegetables and olive oil'],
            ],
        ];

        foreach ($dishes as $index => $dish) {
            if (! $dish['section']) {
                continue;
            }

            $product = Product::updateOrCreate(['slug' => $dish['slug']], [
                'image_path' => 'products/'.$dish['image'],
                'price_cents' => $dish['price_cents'],
                'is_active' => true,
                'sort_order' => 10 + $index,
                'ar' => $dish['ar'],
                'en' => $dish['en'],
            ]);

            MenuItem::updateOrCreate(
                ['category_id' => $dish['section']->id, 'product_id' => $product->id],
                ['menu_id' => $mainMenu->id, 'price_cents' => null, 'is_active' => true, 'is_featured' => false, 'sort_order' => 10 + $index],
            );
        }
    }
}
